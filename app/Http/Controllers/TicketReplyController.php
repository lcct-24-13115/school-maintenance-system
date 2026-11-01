<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forms\StoreTicketReplyRequest;
use App\Models\MaintenanceRequest;
use App\Services\FormNotifier;
use App\Services\TicketWorkflow;
use DomainException;
use Illuminate\Http\RedirectResponse;

/**
 * POST /tickets/{ticket}/replies  (Phase 10)
 *
 * {ticket} is resolved through visibleTo() (hidden = 404); the form request runs TicketPolicy@reply (403);
 * TicketWorkflow::addReply() enforces the state rules and saves the text and files together.
 */
class TicketReplyController extends Controller
{
    public function store(StoreTicketReplyRequest $request, MaintenanceRequest $ticket): RedirectResponse
    {
        $back = route('tickets.show', $ticket).'#replies';

        try {
            TicketWorkflow::addReply($ticket, $request->user(), $request->input('body'), $request->file('files', []));
        } catch (DomainException $e) {
            return redirect()->to($back)->withErrors(['body' => $e->getMessage()], 'thread')->withInput();
        }

        FormNotifier::replied($ticket->refresh(), $request->user());

        return redirect()->to($back)->with('status', 'Message sent.');
    }
}
