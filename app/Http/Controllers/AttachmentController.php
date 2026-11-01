<?php

namespace App\Http\Controllers;

use App\Models\RequestAttachment;
use App\Services\AttachmentService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /attachments/{attachment}  (name: attachments.show)
 *
 * The only way to open an attachment. {attachment} is resolved in AppServiceProvider through the visibility of
 * its ticket (hidden = 404, same as a ticket that does not exist) and TicketPolicy@view is checked again here.
 * Images and PDFs show in the browser; everything else (and ?download=1) downloads.
 */
class AttachmentController extends Controller
{
    public function show(Request $request, RequestAttachment $attachment): Response
    {
        Gate::authorize('view', $attachment->request);

        // Phase 11: a file that belongs to an internal note is for staff only (hidden = 404, like a missing file)
        abort_if($attachment->reply?->is_internal && ! $request->user()->can('viewInternalNotes', $attachment->request), 404);

        $disk = Storage::disk($attachment->storageDisk());
        abort_unless($disk->exists($attachment->path), 404);

        $headers = [
            'Content-Type' => $attachment->servedMime(),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ];

        $name = AttachmentService::sanitizeName((string) $attachment->original_name);
        $inline = $attachment->isInline() && ! $request->boolean('download');

        AuditLogger::log(
            AuditLogger::ATTACHMENT_DOWNLOADED,
            sprintf('%s: %s %s', $attachment->request->ticket_no, $inline ? 'Opened' : 'Downloaded', $name),
            $attachment->request,
            $request->user(),
            ['attachment_id' => $attachment->id],
        );

        if ($inline) {
            return $disk->response($attachment->path, $name, $headers, 'inline');
        }

        return $disk->download($attachment->path, $name, $headers);
    }
}
