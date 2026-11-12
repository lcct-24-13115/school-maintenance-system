<?php

namespace App\Policies;

use App\Models\MaintenanceRequest;
use App\Models\User;

/**
 * Who may do what with a ticket. Capabilities come from config/permissions.php.
 * Seeing the ticket is always required first, so a hidden ticket never reveals which actions exist.
 * (The state rules - "is it Approved yet?" - stay in App\Services\TicketWorkflow.)
 */
class TicketPolicy
{
    /** The list page is open to every active user; scopeVisibleTo() narrows the rows. */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    public function view(User $user, MaintenanceRequest $ticket): bool
    {
        return $ticket->isVisibleTo($user);
    }

    /** File a new repair request. CONFIRM WITH CLIENT: school.filing_roles. */
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole(...(array) config('school.filing_roles', []));
    }

    public function flagEmergency(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'flag_emergency');
    }

    public function clearEmergency(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'clear_emergency');
    }

    /** Approve. WHO is an authorizer is school.workflow.authorizers (CONFIRM WITH CLIENT), not the matrix. */
    public function authorize(User $user, MaintenanceRequest $ticket): bool
    {
        return $user->canAuthorize() && $ticket->isVisibleTo($user);
    }

    /** Disapprove: the same people who may approve. */
    public function disapprove(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->authorize($user, $ticket);
    }

    /** Submitted -> Pending Authorization (Maintenance Admin). */
    public function sendForAuthorization(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'send_for_authorization');
    }

    /**
     * Add a note to the history: the requester, the assignee, and anyone with a working role on tickets
     * (authorizers, maintenance admin, anyone who can assign/work/close/flag). Dept. heads, treasurer,
     * custodian and finance stay view-only. CONFIRM WITH CLIENT.
     */
    public function addNote(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->takesPart($user, $ticket);
    }

    /**
     * Phase 10: write in the conversation on the ticket page. The same people as addNote: the requester, the
     * assignee and the working roles. Everyone who may SEE the ticket may READ the conversation (it is part of
     * the ticket page), so a view-only role reads it but gets no reply form. The status rule (a finished
     * ticket takes no more replies) is in TicketWorkflow::addReply().
     */
    public function reply(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->takesPart($user, $ticket);
    }

    /**
     * Phase 11: read and write INTERNAL notes: the staff side of the conversation (the assignee, the authorizers, the
     * working roles). Never the requester of the ticket, even when that person also has a staff role.
     * Dept. head, treasurer, custodian and finance can read the public conversation only. CONFIRM WITH CLIENT.
     */
    public function viewInternalNotes(User $user, MaintenanceRequest $ticket): bool
    {
        return (int) $ticket->requester_id !== (int) $user->id && $this->takesPart($user, $ticket);
    }

    public function addInternalNote(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->viewInternalNotes($user, $ticket);
    }

    /**
     * Phase 8: cancel YOUR OWN ticket (cancel_own, every role). "Before Assigned" is a STATE rule:
     * TicketWorkflow::cancelBlocker().
     */
    public function cancel(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->ownTicket($user, $ticket) && $user->can_do('cancel_own');
    }

    /** Reopen YOUR OWN ticket (reopen_own, every role). The status and the window: TicketWorkflow::reopenBlocker(). */
    public function reopen(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->ownTicket($user, $ticket) && $user->can_do('reopen_own');
    }

    /** [Confirm resolved]: only the requester of the ticket. (The status rule is in TicketWorkflow::confirmResolved().) */
    public function confirmResolved(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->ownTicket($user, $ticket);
    }

    /** Phase 9: ask the requester for information (request_info = Maintenance Admin). The status rule is in TicketWorkflow. */
    public function requestInfo(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'request_info');
    }

    /** Phase 12: hand the ticket up to the President (escalate = Maintenance Admin). The status rule is in TicketWorkflow. */
    public function escalate(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'escalate');
    }

    /** Phase 9: answer a question about YOUR OWN ticket. */
    public function respondToInfo(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->ownTicket($user, $ticket);
    }

    public function assign(User $user, MaintenanceRequest $ticket): bool
    {
        if (! $this->can($user, $ticket, 'assign')) {
            return false;
        }

        // The Head may act on any ticket. A staff-only member may only act on a ticket that is assigned to them
        // (they transfer it). Taking an unassigned ticket is the board's [ACCEPT TASK].
        return ! $user->isStaffOnly()
            || ($ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $user->id);
    }

    public function work(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'work');
    }

    public function close(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'close');
    }

    /**
     * Add files after filing: anyone with the add_attachment capability (Maintenance Admin), the staff member
     * the ticket is assigned to, or the requester of their OWN ticket while it is not Closed/Disapproved.
     */
    public function addAttachment(User $user, MaintenanceRequest $ticket): bool
    {
        if (! $ticket->isVisibleTo($user)) {
            return false;
        }

        if ($user->can_do('add_attachment')) {
            return true;
        }

        if ($ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $user->id) {
            return true;
        }

        return (int) $ticket->requester_id === (int) $user->id && ! $ticket->status->isTerminal();
    }

    /** The requester, the assignee, or someone with a working role - and the ticket must be visible to them. */
    private function takesPart(User $user, MaintenanceRequest $ticket): bool
    {
        if (! $ticket->isVisibleTo($user)) {
            return false;
        }

        if ((int) $ticket->requester_id === (int) $user->id
            || ($ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $user->id)) {
            return true;
        }

        if ($user->canAuthorize()) {
            return true;
        }

        foreach (['assign', 'work', 'close', 'flag_emergency', 'clear_emergency', 'send_for_authorization'] as $capability) {
            if ($user->can_do($capability)) {
                return true;
            }
        }

        return false;
    }

    private function ownTicket(User $user, MaintenanceRequest $ticket): bool
    {
        return $user->is_active
            && (int) $ticket->requester_id === (int) $user->id
            && $ticket->isVisibleTo($user);
    }

    private function can(User $user, MaintenanceRequest $ticket, string $capability): bool
    {
        return $user->can_do($capability) && $ticket->isVisibleTo($user);
    }
}
