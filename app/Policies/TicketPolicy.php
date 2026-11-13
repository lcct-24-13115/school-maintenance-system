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

    /**
     * Phase 4B: one rule for seeing a ticket, MaintenanceRequest::isVisibleTo() (same as the list scope):
     *  - the requester and the assignee: always
     *  - Department Staff / Head: only tickets whose assigned_department_id is their own department
     *  - System Administrator: every ticket
     * A ticket that is not visible is a 404 before this runs (Route::bind('ticket')); this is the second lock
     * for code that calls Gate / can() directly. Every other ability below starts from view, so a hidden ticket
     * never reveals which actions exist.
     */
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
     * Phase 4C: department scoping comes from visibility (takesPart checks isVisibleTo first), so Finance
     * staff cannot note a Registrar ticket (404 via the binder, false here).
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
     * Phase 4C: same department scoping as addNote (via visibility).
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

    /**
     * Phase 4C: assign / reassign. The Head (and Maintenance Admin / System Administrator) may assign anyone
     * in the department; a staff-only member may only act on a ticket that is assigned to them (they transfer
     * it). Taking an unassigned ticket is the board's [ACCEPT TASK] / claim below.
     * The TARGET's department and the cross-department reason are not known here (this ability only sees the
     * ticket), so they are enforced in TicketWorkflow::assign() with a clear message. Visibility already
     * limits a scoped actor to their own department's tickets (or their own / assigned ones).
     */
    public function assign(User $user, MaintenanceRequest $ticket): bool
    {
        if (! $this->can($user, $ticket, 'assign')) {
            return false;
        }

        return ! $user->isStaffOnly()
            || ($ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $user->id);
    }

    /**
     * Staff rule: a Maintenance Staff member may CLAIM an unassigned ticket (take it for themselves).
     * The Head and the Maintenance Admin do not claim: they assign (assign above). "Is it really unassigned?"
     * and "is it Approved?" are state rules, checked in TicketWorkflow::claim() with a clear message.
     * Phase 4C: claim only inside the staff member's own department. Visibility already hides other
     * departments' tickets, but a staff member who FILED a ticket in another department could still see it
     * as requester, so the department check is repeated here (and again in the workflow).
     */
    public function claim(User $user, MaintenanceRequest $ticket): bool
    {
        return $user->isStaffOnly()
            && $this->can($user, $ticket, 'work')
            && $this->inOwnDepartment($user, $ticket);
    }

    /**
     * Staff rule: a Maintenance Staff member may TRANSFER their OWN ticket to a colleague, with a reason.
     * "Is it really yours?", the reason and the target are checked in TicketWorkflow::transfer().
     * Phase 4C: the target must be in the transferring staff member's own department (checked in the
     * workflow, which knows the target). A cross-department assignee may still transfer to a colleague in
     * their own department, so no ticket-department check is done here.
     */
    public function transfer(User $user, MaintenanceRequest $ticket): bool
    {
        return $user->isStaffOnly() && $this->can($user, $ticket, 'work');
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

    /**
     * Phase 4C: is the ticket in the user's own (scoped) department? Global roles have no scope, so they
     * pass. A ticket with no assigned department (legacy rows) is not blocked here; the workflow decides.
     * No department name or id is hardcoded: both sides come from the user's row and the ticket.
     */
    private function inOwnDepartment(User $user, MaintenanceRequest $ticket): bool
    {
        if (! $user->isDepartmentScoped() || $ticket->assigned_department_id === null) {
            return true;
        }

        return $user->scopedDepartmentId() !== null
            && (int) $ticket->assigned_department_id === (int) $user->scopedDepartmentId();
    }
}
