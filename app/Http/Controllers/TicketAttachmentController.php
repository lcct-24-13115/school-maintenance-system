<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forms\StoreTicketAttachmentRequest;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Services\TicketWorkflow;
use DomainException;
use Illuminate\Http\RedirectResponse;

/** POST /tickets/{ticket}/attachments — add files after the ticket was filed (each upload is one history line). */
class TicketAttachmentController extends Controller
{
    public function store(StoreTicketAttachmentRequest $request, MaintenanceRequest $ticket): RedirectResponse
    {
        // authorize() of the form request already ran TicketPolicy@addAttachment.
        try {
            $created = TicketWorkflow::addAttachments(
                $ticket,
                $request->file('files', []),
                $request->user(),
                $request->input('kind') ?: RequestAttachment::KIND_REQUEST,
            );
        } catch (DomainException $e) {
            return redirect()->to(route('tickets.show', $ticket).'#attachments')->withErrors(['files' => $e->getMessage()]);
        }

        return redirect()->to(route('tickets.show', $ticket).'#attachments')
            ->with('status', $created->count() === 1 ? 'File added.' : $created->count().' files added.');
    }
}
