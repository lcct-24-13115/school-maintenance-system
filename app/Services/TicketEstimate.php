<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Head of Maintenance tells the requester WHEN the work should be finished (after the President approved).
 *
 * The date is saved in the existing requests.due_at (so the overdue badge and the auto-escalation follow it).
 * requests.eta_set_at / eta_set_by / eta_message only record that the Head really gave the requester an estimate.
 * The requester is told by TicketEstimateNotice (in-app alert + queued email).
 * Nothing here touches the rest of TicketWorkflow.
 */
class TicketEstimate
{
    /** Did the Head already give the requester an estimated date? (due_at alone is not enough.) */
    public static function has(MaintenanceRequest $ticket): bool
    {
        return ! empty($ticket->eta_set_at) && $ticket->due_at !== null;
    }

    public static function date(MaintenanceRequest $ticket): ?Carbon
    {
        return self::has($ticket) ? Carbon::parse($ticket->due_at) : null;
    }

    /** Head / Maintenance Admin / System Administrator who can see the ticket. Never a staff-only member. */
    public static function canSet(User $user, MaintenanceRequest $ticket): bool
    {
        return (bool) $user->is_active
            && ! $user->isStaffOnly()
            && $user->can_do('assign')
            && $ticket->isVisibleTo($user);
    }

    public static function set(MaintenanceRequest $ticket, User $by, string $date, ?string $message = null): MaintenanceRequest
    {
        $ticket->refresh();

        if (! in_array($ticket->status, TicketWorkflow::assignableStatuses(), true)) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\". An estimated date can only be given after the President approved it and before the work is finished.");
        }

        if (! self::canSet($by, $ticket)) {
            throw new DomainException('Only the Head of Maintenance can give the requester an estimated completion date.');
        }

        // a scoped actor acts only on their own department's tickets (requester / assignee excepted), same as assign()
        if ($by->isDepartmentScoped() && $ticket->assigned_department_id !== null) {
            $isAssignee = $ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $by->id;
            $isRequester = (int) $ticket->requester_id === (int) $by->id;

            if (! $isAssignee && ! $isRequester
                && ($by->scopedDepartmentId() === null || (int) $ticket->assigned_department_id !== (int) $by->scopedDepartmentId())) {
                throw new DomainException("Ticket {$ticket->ticket_no} belongs to another department. Only that department can set its estimated date.");
            }
        }

        $finish = Carbon::parse($date)->endOfDay();

        if ($finish->isPast()) {
            throw new DomainException('The estimated completion date cannot be in the past.');
        }

        if ($ticket->scheduled_start !== null && Carbon::parse($ticket->scheduled_start)->startOfDay()->gt($finish)) {
            throw new DomainException('The estimated completion date cannot be before the scheduled start date ('.Carbon::parse($ticket->scheduled_start)->format('M j, Y').').');
        }

        $message = filled($message) ? trim((string) $message) : null;
        $previous = self::date($ticket);

        $text = $previous === null
            ? 'Estimated completion date: '.$finish->format('M j, Y').'.'
            : 'Estimated completion date changed from '.$previous->format('M j, Y').' to '.$finish->format('M j, Y').'.';

        if ($message !== null) {
            $text .= ' '.$message;
        }

        DB::transaction(function () use ($ticket, $by, $finish, $message, $previous, $text) {
            $fresh = MaintenanceRequest::query()->lockForUpdate()->find($ticket->getKey());

            if ($fresh === null || $fresh->status !== $ticket->status) {
                throw new DomainException(TicketWorkflow::STALE_MESSAGE);
            }

            $fresh->forceFill([
                'due_at' => $finish,
                'eta_set_at' => now(),
                'eta_set_by' => $by->id,
                'eta_message' => $message,
            ])->save();

            $fresh->logEvent(\App\Models\RequestStatusLog::ACTION_NOTE, $by, $text, meta: [
                'estimate' => true,
                'estimated_date' => $finish->toDateString(),
                'previous_date' => $previous?->toDateString(),
            ]);
        });

        $ticket->refresh();

        // in-app alert + email to the requester (never to the Head himself)
        TicketEstimateNotice::send($ticket, $by);

        return $ticket;
    }
}
