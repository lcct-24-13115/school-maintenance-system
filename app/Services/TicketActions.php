<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Which actions the ticket detail page offers THIS user right now.
 *
 * An action is listed only when (a) the ticket's status allows it (TicketStatus::allowedNext(), or the
 * status rules in TicketWorkflow for actions that keep the status) AND (b) the user has the capability
 * (TicketPolicy). The controllers re-check both, so hiding a button is never the only protection.
 */
class TicketActions
{
    public const SEND_FOR_AUTHORIZATION = 'send_for_authorization';

    public const APPROVE = 'approve';

    public const DISAPPROVE = 'disapprove';

    public const ASSIGN = 'assign';

    public const REASSIGN = 'reassign';

    public const FLAG_EMERGENCY = 'flag_emergency';

    public const CLEAR_EMERGENCY = 'clear_emergency';

    public const ADD_NOTE = 'add_note';

    /** @return list<string> */
    public static function for(MaintenanceRequest $ticket, User $user): array
    {
        $status = $ticket->status;

        if ($status->isTerminal()) {
            return [];   // Closed / Disapproved: nothing more can happen
        }

        $can = fn (string $ability) => Gate::forUser($user)->allows($ability, $ticket);
        $actions = [];

        if ($status->canTransitionTo(TicketStatus::PendingAuthorization) && $can('sendForAuthorization')) {
            $actions[] = self::SEND_FOR_AUTHORIZATION;
        }

        // Approve / Disapprove: from Pending Authorization, and from Submitted only when the config allows it.
        $authorizable = in_array($status->value, MaintenanceRequest::authorizableStatusValues(), true);

        if ($authorizable && $status->canTransitionTo(TicketStatus::Approved) && $can('authorize')) {
            $actions[] = self::APPROVE;
        }
        if ($authorizable && $status->canTransitionTo(TicketStatus::Disapproved) && $can('disapprove')) {
            $actions[] = self::DISAPPROVE;
        }

        if ($can('assign')) {
            if ($status === TicketStatus::Approved) {
                $actions[] = self::ASSIGN;
            } elseif (in_array($status, [TicketStatus::Assigned, TicketStatus::InProgress], true)) {
                $actions[] = self::REASSIGN;
            }
        }

        if (! $ticket->is_emergency && $can('flagEmergency')) {
            $actions[] = self::FLAG_EMERGENCY;
        }
        if ($ticket->is_emergency && $can('clearEmergency')) {
            $actions[] = self::CLEAR_EMERGENCY;
        }

        if ($can('addNote')) {
            $actions[] = self::ADD_NOTE;
        }

        return $actions;
    }

    /**
     * Active maintenance staff with how many open (not yet completed) tickets each one holds,
     * for the Assign / Reassign dropdown.
     *
     * @return Collection<int, User>
     */
    public static function assignableStaff(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', Role::MAINTENANCE_ADMIN))
            ->withCount(['assignedRequests as open_tickets_count' => fn ($q) => $q->pending()])
            ->orderBy('name')
            ->get();
    }
}
