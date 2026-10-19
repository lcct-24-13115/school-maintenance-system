<?php

namespace App\Enums;

/**
 * The 8 ticket statuses from the proposal, plus the ONLY allowed moves between them.
 * Every status change in the app is checked against allowedNext().
 *
 *  Submitted -> Pending Authorization -> Approved -> Assigned -> In Progress
 *            -> Work Completed -> Closed          (Disapproved is a dead end)
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
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::PendingAuthorization, self::Approved, self::Disapproved],
            self::PendingAuthorization => [self::Approved, self::Disapproved],
            self::Approved => [self::Assigned],
            self::Assigned => [self::InProgress],
            self::InProgress => [self::WorkCompleted],
            self::WorkCompleted => [self::Closed, self::InProgress],
            self::Closed, self::Disapproved => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** Closed and Disapproved: nothing more can happen to the ticket. */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Work is done (Work Completed or Closed). Used for "completed" counts. */
    public function isCompleted(): bool
    {
        return in_array($this, self::completedCases(), true);
    }

    /** Still needs attention: not completed, not closed, not disapproved. Used for "pending" counts. */
    public function isPending(): bool
    {
        return in_array($this, self::pendingCases(), true);
    }

    /** @return list<self> */
    public static function pendingCases(): array
    {
        return [self::Submitted, self::PendingAuthorization, self::Approved, self::Assigned, self::InProgress];
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
