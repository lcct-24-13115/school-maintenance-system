<?php

namespace App\Http\Controllers;

use App\Models\RequestAttachment;
use App\Models\RequestStatusLog;
use App\Services\AttachmentService;
use App\Services\AuditLogger;
use App\Services\DocxPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET    /attachments/{attachment}        (attachments.show)     open / download
 * GET    /attachments/{attachment}/text   (attachments.text)     simplified text of a .docx for the viewer pop-up
 * DELETE /attachments/{attachment}        (attachments.destroy)  remove an uploaded file (written to the audit log)
 *
 * The only way to reach an attachment. {attachment} is resolved in AppServiceProvider through the visibility of
 * its ticket (hidden = 404, same as a ticket that does not exist) and TicketPolicy@view is checked again here.
 * Images and PDFs show in the browser; everything else (and ?download=1) downloads.
 */
class AttachmentController extends Controller
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function show(Request $request, RequestAttachment $attachment): Response
    {
        $this->authorizeView($request, $attachment);

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

    /** Plain-text preview of a Word file for the pop-up viewer. {"text": ""} when nothing can be shown. */
    public function text(Request $request, RequestAttachment $attachment): JsonResponse
    {
        $this->authorizeView($request, $attachment);
        abort_unless($attachment->mime_type === self::DOCX_MIME, 404);

        $disk = Storage::disk($attachment->storageDisk());
        abort_unless($disk->exists($attachment->path), 404);

        $text = DocxPreview::text($disk->path($attachment->path));

        AuditLogger::log(
            AuditLogger::ATTACHMENT_DOWNLOADED,
            sprintf('%s: Previewed %s', $attachment->request->ticket_no, AttachmentService::sanitizeName((string) $attachment->original_name)),
            $attachment->request,
            $request->user(),
            ['attachment_id' => $attachment->id, 'preview' => 'text'],
        );

        return response()->json(['text' => $text ?? ''], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Remove an uploaded file. The row and the stored file go; the ticket history and the audit log keep a line
     * saying who removed which file (name, kind, size - never the file itself or its storage path).
     */
    public function destroy(Request $request, RequestAttachment $attachment): RedirectResponse
    {
        $this->authorizeView($request, $attachment);
        Gate::authorize('delete', $attachment);

        $ticket = $attachment->request;
        $by = $request->user();
        $name = AttachmentService::sanitizeName((string) $attachment->original_name);
        $kind = $attachment->kindLabel();
        $disk = $attachment->storageDisk();
        $path = $attachment->path;
        $meta = [
            'attachment_id' => $attachment->id,
            'kind' => $attachment->kind,
            'size_bytes' => $attachment->size_bytes,
            'uploaded_by' => $attachment->uploaded_by,
            'in_reply' => $attachment->reply_id !== null,
        ];

        DB::transaction(function () use ($attachment, $ticket, $by, $name, $kind, $meta) {
            $attachment->delete();
            $ticket->logEvent(RequestStatusLog::ACTION_ATTACHMENT, $by, sprintf('Removed file (%s): %s', $kind, $name), meta: ['removed' => $meta]);
        });

        AuditLogger::log(
            AuditLogger::ATTACHMENT_DELETED,
            sprintf('%s: Removed %s (%s)', $ticket->ticket_no, $name, $kind),
            $ticket,
            $by,
            $meta,
        );

        // The row is gone; now the file. A leftover file on the private disk is harmless, so a failure only gets reported.
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->to(route('tickets.show', $ticket).'#attachments')->with('status', 'File removed.');
    }

    /** The ticket must be visible (policy) and a file inside an internal note is for staff only (hidden = 404). */
    private function authorizeView(Request $request, RequestAttachment $attachment): void
    {
        Gate::authorize('view', $attachment->request);

        abort_if($attachment->reply?->is_internal && ! $request->user()->can('viewInternalNotes', $attachment->request), 404);
    }
}
