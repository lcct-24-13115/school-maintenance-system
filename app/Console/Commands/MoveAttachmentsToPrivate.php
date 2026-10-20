<?php

namespace App\Console\Commands;

use App\Models\RequestAttachment;
use App\Services\AttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Phase 3: files saved before this phase are on the PUBLIC disk (reachable at /storage/...).
 * This moves them to the private disk and updates the row. Safe to run again: it only touches rows still on 'public'.
 */
class MoveAttachmentsToPrivate extends Command
{
    protected $signature = 'attachments:move-to-private
        {--dry-run : Only show what would be moved}
        {--keep-source : Copy to the private disk but leave the public file in place}';

    protected $description = 'Move existing attachment files from the public disk to the private disk and update their rows';

    public function handle(): int
    {
        $source = Storage::disk(RequestAttachment::LEGACY_DISK);
        $target = Storage::disk(RequestAttachment::DISK);
        $dry = (bool) $this->option('dry-run');
        $keep = (bool) $this->option('keep-source');

        $moved = $missing = $failed = 0;

        RequestAttachment::query()
            ->where('disk', RequestAttachment::LEGACY_DISK)
            // eachById (not each): rows leave this filter while we go, which would make offset paging skip some
            ->eachById(function (RequestAttachment $attachment) use ($source, $target, $dry, $keep, &$moved, &$missing, &$failed) {
                $old = $attachment->path;

                if (! $source->exists($old)) {
                    $this->warn("#{$attachment->id}: file not found on the public disk ({$old}) - skipped.");
                    $missing++;

                    return;
                }

                $new = 'ticket-attachments/'.($attachment->created_at ?? now())->format('Y/m').'/'.basename($old);

                if ($dry) {
                    $this->line("#{$attachment->id}: would move {$old} -> {$new}");
                    $moved++;

                    return;
                }

                try {
                    $stream = $source->readStream($old);
                    if (! is_resource($stream)) {
                        throw new RuntimeException('cannot read the file');
                    }

                    $written = $target->writeStream($new, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if ($written === false || ! $target->exists($new) || $target->size($new) !== $source->size($old)) {
                        throw new RuntimeException('the copy is missing or has a different size');
                    }

                    // The old row kept the BROWSER's mime type; replace it with the real one from the contents.
                    $detected = AttachmentService::detectMime($target->path($new));

                    $attachment->update([
                        'path' => $new,
                        'disk' => RequestAttachment::DISK,
                        'size_bytes' => $target->size($new),
                        'mime_type' => $detected ?? $attachment->mime_type,
                    ]);

                    if (! $keep) {
                        $source->delete($old);
                    }

                    $moved++;
                } catch (Throwable $e) {
                    $target->delete($new);   // never leave a half copy
                    $this->error("#{$attachment->id}: {$e->getMessage()}");
                    $failed++;
                }
            });

        $verb = $dry ? 'would be moved' : ($keep ? 'copied' : 'moved');
        $this->info("{$moved} file(s) {$verb}, {$missing} missing, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
