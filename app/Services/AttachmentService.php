<?php

namespace App\Services;

use App\Models\RequestAttachment;
use DomainException;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The ONE place that decides whether a file may be accepted and where it is saved.
 * Settings: config/school.php -> attachments. Used by App\Rules\ValidAttachment (forms) and
 * TicketWorkflow (every other way a file can reach a ticket).
 *
 * The browser's mime type and the file name are NEVER trusted: the type is detected from the file contents.
 */
class AttachmentService
{
    // ---- settings ------------------------------------------------------------------------------

    /** @return array<string, array{mime: string, detected: list<string>, inline: bool}> */
    public static function types(): array
    {
        return (array) config('school.attachments.types', []);
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_keys(self::types());
    }

    public static function maxBytes(): int
    {
        return (int) config('school.attachments.max_kb', 5120) * 1024;
    }

    public static function maxFiles(): int
    {
        return (int) config('school.attachments.max_files', 5);
    }

    /** For <input accept="...">, e.g. ".jpg,.jpeg,.png". */
    public static function accept(): string
    {
        return implode(',', array_map(fn ($e) => '.'.$e, self::extensions()));
    }

    public static function allowedLabel(): string
    {
        return strtoupper(implode(', ', self::extensions()));
    }

    public static function maxSizeLabel(): string
    {
        return rtrim(rtrim(number_format(self::maxBytes() / 1048576, 1), '0'), '.').' MB';
    }

    /** @return list<string> */
    public static function inlineMimes(): array
    {
        return array_values(array_unique(array_column(array_filter(self::types(), fn ($t) => $t['inline']), 'mime')));
    }

    /** @return list<string> */
    public static function knownMimes(): array
    {
        return array_values(array_unique(array_column(self::types(), 'mime')));
    }

    // ---- names ---------------------------------------------------------------------------------

    /** A name that is safe to show and to put in a Content-Disposition header. */
    public static function sanitizeName(string $name): string
    {
        $name = mb_scrub($name);
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/[<>:"\/\\\\|?*]+/u', '_', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " .\t");

        if ($name === '') {
            return 'file';
        }

        if (mb_strlen($name) > 100) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, max(1, 99 - mb_strlen($ext)));
            $name = $ext !== '' ? $base.'.'.$ext : $base;
        }

        return $name;
    }

    // ---- validation ----------------------------------------------------------------------------

    /** Real type from the file CONTENTS (finfo), lower-cased; null when it cannot be read. */
    public static function detectMime(string $path): ?string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime === false ? null : strtolower($mime);
    }

    /** Error message for one file, or null when it is fine. */
    public static function check(UploadedFile $file): ?string
    {
        return self::inspect($file)[0];
    }

    /** Error message for a whole upload (count + every file), or null. Used outside form requests. */
    public static function checkMany(array $files): ?string
    {
        if (count($files) > self::maxFiles()) {
            return 'You can upload at most '.self::maxFiles().' files at a time.';
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                return 'Please choose a file to upload.';
            }
            if ($error = self::check($file)) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}  [error message, lower-case extension, canonical mime]
     */
    public static function inspect(UploadedFile $file): array
    {
        $raw = (string) $file->getClientOriginalName();
        $shown = '“'.self::sanitizeName($raw).'”';
        $limit = self::maxSizeLabel();

        if (! $file->isValid()) {
            $tooBig = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            return [$tooBig
                ? "{$shown} is too large. The maximum is {$limit} per file."
                : "{$shown} could not be uploaded. Please try again.", null, null];
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            return ["{$shown} is empty.", null, null];
        }
        if ($size > self::maxBytes()) {
            $mb = number_format($size / 1048576, 1);

            return ["{$shown} is {$mb} MB. The maximum is {$limit} per file.", null, null];
        }

        $ext = strtolower(pathinfo($raw, PATHINFO_EXTENSION));
        $types = self::types();
        if ($ext === '' || ! isset($types[$ext])) {
            return ["{$shown} is not an allowed file type. Allowed: ".self::allowedLabel().'.', null, null];
        }

        if (self::hasDoubleExtension($raw)) {
            return ["{$shown} has more than one file extension (like file.php.jpg). Rename it with a single extension and try again.", null, null];
        }

        $path = $file->getRealPath();
        $detected = $path ? self::detectMime($path) : null;
        if ($detected === null || ! in_array($detected, $types[$ext]['detected'], true)) {
            return ["{$shown} does not look like a real .{$ext} file (its contents are ".($detected ?? 'unreadable').'). Upload the original file.', null, null];
        }

        if (in_array($ext, ['docx', 'xlsx'], true) && ! self::looksLikeOoxml($path, $ext)) {
            return ["{$shown} does not look like a real .{$ext} file. Upload the original file.", null, null];
        }

        return [null, $ext, $types[$ext]['mime']];
    }

    /** "file.php.jpg": a middle part that is a script/program extension or another allowed extension. */
    private static function hasDoubleExtension(string $raw): bool
    {
        $parts = explode('.', basename(str_replace('\\', '/', $raw)));
        array_pop($parts);      // the real extension
        array_shift($parts);    // the name itself

        $watch = array_merge(
            array_map('strtolower', (array) config('school.attachments.blocked_inner_extensions', [])),
            self::extensions()
        );

        foreach ($parts as $part) {
            if (in_array(strtolower(trim($part)), $watch, true)) {
                return true;
            }
        }

        return false;
    }

    /** A genuine Word/Excel file is a zip that contains [Content_Types].xml and word/document.xml (or xl/workbook.xml). */
    private static function looksLikeOoxml(string $path, string $ext): bool
    {
        $bytes = @file_get_contents($path);   // already limited to max_kb above
        if ($bytes === false || ! str_starts_with($bytes, "PK\x03\x04")) {
            return false;
        }

        $marker = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';

        return str_contains($bytes, '[Content_Types].xml') && str_contains($bytes, $marker);
    }

    // ---- saving --------------------------------------------------------------------------------

    /**
     * Validate again and save to the PRIVATE disk as ticket-attachments/{year}/{month}/{random}.{ext}.
     *
     * @return array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int}
     */
    public static function store(UploadedFile $file): array
    {
        [$error, $ext, $mime] = self::inspect($file);
        if ($error !== null) {
            throw new DomainException($error);
        }

        $size = (int) $file->getSize();
        $disk = RequestAttachment::DISK;
        $dir = 'ticket-attachments/'.now()->format('Y/m');
        $name = Str::lower(Str::random(40)).'.'.$ext;

        $path = Storage::disk($disk)->putFileAs($dir, $file, $name);
        if ($path === false) {
            throw new DomainException('The file could not be saved. Please try again.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => self::sanitizeName((string) $file->getClientOriginalName()),
            'mime_type' => $mime,
            'size_bytes' => $size,
        ];
    }

    /** Remove files saved by store() when the database work around them failed. */
    public static function discard(array $stored): void
    {
        foreach ($stored as [$disk, $path]) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable) {
                // nothing more we can do; an orphan file is harmless (private disk)
            }
        }
    }
}
