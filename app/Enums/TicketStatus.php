<?php

namespace App\Enums;

/**
 * The ticket statuses and the ONLY allowed moves between them.
 * Every status change in the app is checked against allowedNext().
 *
 * Main path (the 8 statuses of the proposal):
 *
 *  Submitted -> Pending Authorization -> Approved -> Assigned -> In Progress
 *            -> Work Completed -> Closed          (Disapproved is a dead end)
 *
 * Phase 7 adds four more (the foundation for Phases 8-10; this phase only DEFINES them, the buttons come later):
 *
 *  Waiting for User  staff asked the requester for information (needs request_info; Phase 8).
 *                    It goes BACK to where it was (Submitted ... In Progress); the earlier status is in the history.
 *  Escalated         handed up to the President / authorizer (needs escalate; Phase 9). Handing it back is the authorizer's.
 *  Cancelled         the requester cancelled it, before it was Assigned (cancel_own; Phase 8). A dead end like Disapproved.
 *                    allowedNext() only says which statuses MAY cancel; the "before Assigned" rule is the policy's job.
 *  Reopened          a Work Completed / Closed ticket was reopened inside reopen_window_days (reopen_own; Phase 10).
 *                    It goes back to work: Assigned or In Progress.
 *
 * Work Completed -> In Progress is allowed so a ticket can be sent back for rework.
 * Exact signatories/transitions are still subject to client confirmation (proposal §4).
 */
enum TicketStatus: string
{
    case Submitted = 'submitted';
    case PendingAuthorization = 'pending_authorization';
    case Approved = 'approved';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case WorkCompleted = 'work_completed';
    case Closed = 'closed';
    case Disapproved = 'disapproved';
    case WaitingForUser = 'waiting_for_user';
    case Escalated = 'escalated';
    case Cancelled = 'cancelled';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::PendingAuthorization => 'Pending Authorization',
            self::Approved => 'Approved',
            self::Assigned => 'Assigned',
            self::InProgress => 'In Progress',
            self::WorkCompleted => 'Work Completed',
            self::Closed => 'Closed',
            self::Disapproved => 'Disapproved',
            self::WaitingForUser => 'Waiting for User',
            self::Escalated => 'Escalated',
            self::Cancelled => 'Cancelled',
            self::Reopened => 'Reopened',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::PendingAuthorization, self::Approved, self::Disapproved, self::WaitingForUser, self::Escalated, self::Cancelled],
            self::PendingAuthorization => [self::Approved, self::Disapproved, self::WaitingForUser, self::Escalated, self::Cancelled],
            self::Approved => [self::Assigned, self::WaitingForUser, self::Escalated, self::Cancelled],
            self::Assigned => [self::InProgress, self::WaitingForUser, self::Escalated],
            self::InProgress => [self::WorkCompleted, self::WaitingForUser, self::Escalated],
            self::WorkCompleted => [self::Closed, self::InProgress, self::Reopened],
            self::Closed => [self::Reopened],
            self::WaitingForUser => [self::Submitted, self::PendingAuthorization, self::Approved, self::Assigned, self::InProgress, self::Reopened, self::Escalated, self::Cancelled],
            self::Escalated => [self::PendingAuthorization, self::Approved, self::Disapproved, self::Assigned, self::InProgress, self::Reopened, self::Cancelled],
            self::Reopened => [self::Assigned, self::InProgress, self::WaitingForUser, self::Escalated],
            self::Disapproved, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * Closed, Disapproved and Cancelled: the ticket is finished, so no more notes, flags, assignments or files.
     * (Closed can still be REOPENED, but only through the reopen action of Phase 10, never through the normal
     * ticket actions. That is why this is a fixed list and not "allowedNext() is empty".)
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Disapproved, self::Cancelled], true);
    }

    /** Work is done (Work Completed or Closed). Used for "completed" counts. */
    public function isCompleted(): bool
    {
        return in_array($this, self::completedCases(), true);
    }

    /**
     * Still needs attention: not completed, not closed, not disapproved, not cancelled. Used for "pending" counts.
     * Waiting for User, Escalated and Reopened are still open work, so they count (and can become Overdue).
     */
    public function isPending(): bool
    {
        return in_array($this, self::pendingCases(), true);
    }

    /** @return list<self> */
    public static function pendingCases(): array
    {
        return [
            self::Submitted, self::PendingAuthorization, self::Approved, self::Assigned, self::InProgress,
            self::WaitingForUser, self::Escalated, self::Reopened,
        ];
    }

    /** @return list<self> */
    public static function completedCases(): array
    {
        return [self::WorkCompleted, self::Closed];
    }

    /** @return list<string> */
    public static function pendingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::pendingCases());
    }

    /** @return list<string> */
    public static function completedValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::completedCases());
    }

    /** value => label, handy for filter dropdowns. */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
