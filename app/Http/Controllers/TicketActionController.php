<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Rules\ValidAttachment;
use App\Services\AttachmentService;
use App\Services\FormNotifier;
use App\Services\TicketWorkflow;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The action buttons of the ticket detail page and the approvals inbox (Phase 4A).
 *
 * Every method does the same three things, in this order:
 *   1. Gate::authorize(...)  - {ticket} is already resolved through visibleTo() (hidden = 404); a visible ticket
 *                              without the capability is a 403.
 *   2. validate the form      - errors go to a named bag so several forms on one page do not mix them up.
 *   3. call TicketWorkflow    - it enforces the STATE rules; a DomainException is shown as a flash error.
 *
 * No notifications are sent here (Phase 5 hooks them into TicketWorkflow).
 */
class TicketActionController extends Controller
{
    public function sendForAuthorization(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'sendForAuthorization', 'send', [], [],
            fn () => TicketWorkflow::sendForAuthorization($ticket, $request->user()),
            'Sent for authorization.');
    }

    public function approve(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'authorize', 'approve', [
            'remarks' => ['nullable', 'string', 'max:1000'],
            'files' => ['nullable', 'array', 'max:'.AttachmentService::maxFiles()],
            'files.*' => [new ValidAttachment],
        ], [],
            fn (array $data) => TicketWorkflow::authorize($ticket, $request->user(), $data['remarks'] ?? null, $request->file('files', [])),
            'Approved. Authorization is recorded.');
    }

    public function disapprove(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'disapprove', 'disapprove', [
            'reason' => ['required', 'string', 'max:1000'],
        ], ['reason.required' => 'Disapproving a ticket needs a reason.'],
            fn (array $data) => TicketWorkflow::disapprove($ticket, $request->user(), $data['reason']),
            'Disapproved.');
    }

    public function assign(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        $isReassignment = $ticket->assigned_to !== null;

        return $this->act($request, $ticket, 'assign', 'assign', [
            'assignee_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'assign_note' => [Rule::requiredIf($isReassignment), 'nullable', 'string', 'max:500'],
        ], [
            'assignee_id.required' => 'Choose a staff member.',
            'assign_note.required' => 'A reassignment needs a note explaining why.',
        ],
            fn (array $data) => TicketWorkflow::assign(
                $ticket, User::findOrFail($data['assignee_id']), $request->user(), $data['assign_note'] ?? null
            ),
            $isReassignment ? 'Reassigned.' : 'Assigned.');
    }

    public function flagEmergency(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'flagEmergency', 'flag', [
            'emergency_reason' => ['required', 'string', 'max:500'],
        ], ['emergency_reason.required' => 'An emergency flag needs a reason.'],
            fn (array $data) => TicketWorkflow::flagEmergency($ticket, $request->user(), $data['emergency_reason']),
            'Emergency flag raised. The ticket moved to the top of the queue.');
    }

    public function clearEmergency(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'clearEmergency', 'clear', [
            'clear_note' => ['nullable', 'string', 'max:500'],
        ], [],
            fn (array $data) => TicketWorkflow::clearEmergency($ticket, $request->user(), $data['clear_note'] ?? null),
            'Emergency flag removed. The ticket is back in its original place in the queue.');
    }

    /** Phase 8: the requester cancels their own ticket, before it is Assigned. */
    public function cancel(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'cancel', 'cancel', [
            'cancel_reason' => ['required', 'string', 'max:500'],
        ], ['cancel_reason.required' => 'Cancelling a ticket needs a reason.'],
            function (array $data) use ($request, $ticket) {
                TicketWorkflow::cancel($ticket, $request->user(), $data['cancel_reason']);
                FormNotifier::requesterAction($ticket->refresh(), $request->user(), 'cancelled');
            },
            'Ticket cancelled.');
    }

    /** Phase 8: [Confirm resolved] on a Work Completed ticket closes it at once. */
    public function confirmResolved(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'confirmResolved', 'confirm', [], [],
            function () use ($request, $ticket) {
                TicketWorkflow::confirmResolved($ticket, $request->user());
                FormNotifier::requesterAction($ticket->refresh(), $request->user(), 'confirmed');
            },
            'Thank you. The ticket is closed.');
    }

    /** Phase 8: the requester reopens their own Work Completed / Closed ticket inside reopen_window_days. */
    public function reopen(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'reopen', 'reopen', [
            'reopen_reason' => ['required', 'string', 'max:500'],
        ], ['reopen_reason.required' => 'Reopening a ticket needs a reason.'],
            function (array $data) use ($request, $ticket) {
                TicketWorkflow::reopen($ticket, $request->user(), $data['reopen_reason']);
                FormNotifier::requesterAction($ticket->refresh(), $request->user(), 'reopened');
            },
            'Ticket reopened. Maintenance has been alerted.');
    }

    public function note(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        return $this->act($request, $ticket, 'addNote', 'note', [
            'note' => ['required', 'string', 'max:1000'],
        ], ['note.required' => 'A note cannot be empty.'],
            fn (array $data) => TicketWorkflow::addNote($ticket, $request->user(), $data['note']),
            'Note added.');
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     */
    private function act(
        Request $request,
        MaintenanceRequest $ticket,
        string $ability,
        string $bag,
        array $rules,
        array $messages,
        Closure $do,
        string $okMessage,
    ): RedirectResponse {
        Gate::authorize($ability, $ticket);

        $data = $rules === [] ? [] : $request->validateWithBag($bag, $rules, $messages);

        try {
            $do($data);
        } catch (DomainException $e) {
            return $this->backTo($request, $ticket)->with('error', $e->getMessage())->withInput();
        }

        return $this->backTo($request, $ticket)->with('status', $okMessage);
    }

    /** The approvals inbox sends return=approvals so the authorizer stays in the inbox; otherwise the ticket page. */
    private function backTo(Request $request, MaintenanceRequest $ticket): RedirectResponse
    {
        if ($request->input('return') === 'approvals' && $request->user()->canAuthorize()) {
            return redirect()->route('approvals.index');
        }

        return redirect()->to(route('tickets.show', $ticket).'#actions');
    }
}
