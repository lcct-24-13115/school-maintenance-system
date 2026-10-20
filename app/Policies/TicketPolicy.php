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

    public function authorize(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'authorize');
    }

    public function assign(User $user, MaintenanceRequest $ticket): bool
    {
        return $this->can($user, $ticket, 'assign');
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

    private function can(User $user, MaintenanceRequest $ticket, string $capability): bool
    {
        return $user->can_do($capability) && $ticket->isVisibleTo($user);
    }
}
