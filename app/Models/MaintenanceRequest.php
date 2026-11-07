<?php

namespace App\Models;

use App\Enums\TicketStatus;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * THE maintenance ticket. Table is "requests"; the model is named MaintenanceRequest to avoid
 * clashing with Illuminate\Http\Request.
 *
 * Change status / assignment / emergency flag ONLY through App\Services\TicketWorkflow so every
 * change is validated and written to request_status_logs.
 */
class MaintenanceRequest extends Model
{
    use SoftDeletes;

    protected $table = 'requests';

    public const LABEL = 'Maintenance Ticket';

    public const TICKET_PREFIX = 'MT';

    // Stored in the legacy `priority` column; kept in sync with is_emergency automatically.
    public const PRIORITY_FCFS = 'first_come_first_served';

    public const PRIORITY_EMERGENCY = 'emergency';

    protected $fillable = [
        'ticket_no', 'requester_id', 'department_id', 'category_id',
        'request_type', 'maintenance_type', 'location', 'description', 'needed_by', 'reason',
        'priority', 'is_emergency', 'emergency_reason', 'emergency_flagged_by', 'emergency_flagged_at',
        'is_confidential',
        'status', 'assigned_to', 'assigned_at',
        'president_id', 'president_approved_at', 'president_remarks',
        'problems_encountered', 'recommendations',
        'pm_schedule_id', 'pm_due_date',
        'submitted_at', 'due_at', 'completed_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'is_emergency' => 'boolean',
            'is_confidential' => 'boolean',
            'needed_by' => 'date',
            'pm_due_date' => 'date',
            'pm_completed_date' => 'date',
            'submitted_at' => 'datetime',
            'due_at' => 'datetime',
            'assigned_at' => 'datetime',
            'emergency_flagged_at' => 'datetime',
            'president_approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            $ticket->submitted_at ??= now();
            $ticket->status ??= TicketStatus::Submitted;
            $ticket->ticket_no ??= self::generateTicketNo($ticket->submitted_at);
            $ticket->due_at ??= self::computeDueAt($ticket->submitted_at, (bool) $ticket->is_emergency);
        });

        static::saving(function (self $ticket) {
            // M1: flagging / clearing an emergency changes the SLA class, so the target date is recomputed
            // from the ORIGINAL submission time (submitted_at never moves).
            if ($ticket->exists && $ticket->isDirty('is_emergency') && $ticket->submitted_at) {
                $ticket->due_at = self::computeDueAt($ticket->submitted_at, (bool) $ticket->is_emergency);
            }

            // Rule 1: a ticket classified as materials/equipment/supplies must use a category of that type.
            // (request_type is optional: pure service work such as a repair has none.)
            if ($ticket->request_type && $ticket->isDirty(['category_id', 'request_type'])) {
                $category = Category::find($ticket->category_id);
                if ($category && $category->type !== $ticket->request_type) {
                    throw new DomainException('Category does not match the request type (materials/equipment/supplies).');
                }
            }

            // Rule 2: an emergency flag always carries a reason.
            if ($ticket->is_emergency && blank($ticket->emergency_reason)) {
                throw new DomainException('An emergency flag needs a reason.');
            }

            // The legacy `priority` column mirrors is_emergency (one source of truth: is_emergency).
            $ticket->priority = $ticket->is_emergency ? self::PRIORITY_EMERGENCY : self::PRIORITY_FCFS;

            // Rule 3: authorization evidence before closure.
            // (Phase 3 extends this with uploaded signed evidence.)
            if ($ticket->isDirty('status') && $ticket->status === TicketStatus::Closed && ! $ticket->isPresidentApproved()) {
                throw new DomainException('A ticket cannot be closed without recorded authorization (School President approval).');
            }
        });

        // Safety net: even a direct ->update(['status' => ...]) cannot skip steps.
        static::updating(function (self $ticket) {
            if (! $ticket->isDirty('status')) {
                return;
            }

            $from = TicketStatus::tryFrom((string) $ticket->getRawOriginal('status'));
            $to = $ticket->status;

            if ($from && $to && $from !== $to && ! $from->canTransitionTo($to)) {
                throw new DomainException(self::invalidTransitionMessage($ticket->ticket_no, $from, $to));
            }
        });
    }

    public static function invalidTransitionMessage(?string $ticketNo, TicketStatus $from, TicketStatus $to): string
    {
        $allowed = collect($from->allowedNext())->map->label()->implode(', ');

        return sprintf(
            'Ticket %s cannot move from "%s" to "%s". %s',
            $ticketNo ?? '(new)',
            $from->label(),
            $to->label(),
            $allowed === '' ? 'This status is final.' : "Allowed next status: {$allowed}."
        );
    }

    /**
     * Next number for the year of $at, e.g. MT-2026-00001. Numbers restart each year.
     * The unique index on ticket_no is the real guard; TicketWorkflow::open() retries on a clash.
     */
    public static function generateTicketNo(?Carbon $at = null): string
    {
        $prefix = sprintf('%s-%d-', self::TICKET_PREFIX, ($at ?? now())->year);

        $last = static::withTrashed()
            ->where('ticket_no', 'like', $prefix.'%')
            ->orderByDesc('ticket_no')
            ->value('ticket_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /** Old name used by the notification service and the 3 school forms. */
    public function getReferenceNoAttribute(): ?string
    {
        return $this->ticket_no;
    }

    // ---- due date / overdue (Merge Phase M1) -----------------------------------------------------

    /** Target completion = submission time + SLA hours (Setting: sla_hours_routine / sla_hours_emergency). */
    public static function computeDueAt(Carbon $submittedAt, bool $emergency): Carbon
    {
        $hours = Setting::int($emergency ? 'sla_hours_emergency' : 'sla_hours_routine', $emergency ? 24 : 120);

        return $submittedAt->copy()->addHours(max($hours, 1));
    }

    /** Past its target date and still pending (Work Completed, Closed and Disapproved are never overdue). */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereIn('status', TicketStatus::pendingValues())
            ->where('status', '!=', TicketStatus::WaitingForUser->value);   // Phase 9: the SLA clock is paused while waiting
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && $this->status->isPending()
            && $this->status !== TicketStatus::WaitingForUser;
    }

    // ---- queue ---------------------------------------------------------------------------------

    /** Emergency first, then routine tickets strictly by ORIGINAL submission time (FCFS). */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_emergency')->orderBy('submitted_at')->orderBy('id');
    }

    /** Routine queue only, in first-come-first-served order. */
    public function scopeRoutineQueue(Builder $query): Builder
    {
        return $query->where('is_emergency', false)->orderBy('submitted_at')->orderBy('id');
    }

    /** Not completed, not closed, not disapproved. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', TicketStatus::pendingValues());
    }

    /**
     * Tickets an authorizer can act on: Pending Authorization, plus Submitted when
     * school.workflow.authorize_from_submitted is on.
     */
    public function scopeAwaitingAuthorization(Builder $query): Builder
    {
        return $query->whereIn('status', self::authorizableStatusValues());
    }

    /** @return list<string> */
    public static function authorizableStatusValues(): array
    {
        $statuses = [TicketStatus::PendingAuthorization->value];

        if (config('school.workflow.authorize_from_submitted', true)) {
            $statuses[] = TicketStatus::Submitted->value;
        }

        return $statuses;
    }

    /** Phase 14: still active = everything except Closed, Disapproved and Cancelled (those live in Ticket History). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', TicketStatus::terminalValues());
    }

    /** Phase 14: finished tickets only (Closed, Disapproved, Cancelled). */
    public function scopeHistory(Builder $query): Builder
    {
        return $query->whereIn('status', TicketStatus::terminalValues());
    }

    /** Work Completed or Closed. */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', TicketStatus::completedValues());
    }

    /**
     * Only the tickets $user may see. USE THIS in every query that lists, counts or exports tickets.
     * Keep it in step with isVisibleTo() below (a test compares the two).
     *
     *  - your own tickets and tickets assigned to you: always
     *  - confidential tickets: only with view_confidential (plus the two cases above)
     *  - non-confidential tickets: with view_all_tickets, or your own department for department roles
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('requester_id', $user->id)->orWhere('assigned_to', $user->id);

            if ($user->can_do('view_confidential')) {
                $q->orWhere('is_confidential', true);
            }

            if ($user->can_do('view_all_tickets')) {
                $q->orWhere('is_confidential', false);
            } elseif ($user->seesOwnDepartment()) {
                $q->orWhere(fn (Builder $d) => $d->where('is_confidential', false)->where('department_id', $user->department_id));
            }
        });
    }

    /** Same rule as scopeVisibleTo, for a single loaded ticket (policies, notifications). */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ((int) $this->requester_id === (int) $user->id || ((int) $this->assigned_to === (int) $user->id && $this->assigned_to !== null)) {
            return true;
        }

        if ($this->is_confidential) {
            return $user->can_do('view_confidential');
        }

        return $user->can_do('view_all_tickets')
            || ($user->seesOwnDepartment() && (int) $this->department_id === (int) $user->department_id);
    }

    // ---- helpers -------------------------------------------------------------------------------

    public function isEmergency(): bool
    {
        return $this->is_emergency;
    }

    public function isPresidentApproved(): bool
    {
        return $this->president_id !== null && $this->president_approved_at !== null;
    }

    /** First request photo, for the card on the maintenance board (an authorized route, never a /storage URL). */
    public function photoUrl(): ?string
    {
        $photo = $this->attachments->first(fn (RequestAttachment $a) => $a->isRequestFile() && $a->isImage());

        return $photo?->url();
    }

    /** Write one line in the history (status change, assignment, emergency flag, note...). */
    public function logEvent(
        string $action,
        ?User $by = null,
        ?string $note = null,
        ?TicketStatus $from = null,
        ?TicketStatus $to = null,
        ?array $meta = null,
    ): RequestStatusLog {
        return $this->statusLogs()->create([
            'user_id' => $by?->id,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'note' => $note,
            'meta' => $meta,
        ]);
    }

    // ---- relations -----------------------------------------------------------------------------

    /** Made by the PM Scheduler (still true after the schedule is deleted, because pm_due_date stays). */
    public function isPmGenerated(): bool
    {
        return $this->pm_schedule_id !== null || $this->pm_due_date !== null;
    }

    /** The checklist of a PM ticket (a copy of the schedule's template). */
    public function pmChecklist(): HasMany
    {
        return $this->hasMany(PmTicketChecklistItem::class, 'request_id')->orderBy('sort_order')->orderBy('id');
    }

    public function pmTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_technician_id');
    }

    public function pmSchedule(): BelongsTo
    {
        return $this->belongsTo(PmSchedule::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function president(): BelongsTo
    {
        return $this->belongsTo(User::class, 'president_id');
    }

    public function emergencyFlagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emergency_flagged_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequestAttachment::class, 'request_id');
    }

    /**
     * Phase 10: the PUBLIC conversation (oldest first). Internal notes (Phase 11) are never in this relation, so
     * code that forgets about them cannot leak them. Read it only after TicketPolicy@view passed.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class, 'request_id')->public()->oldest('id');
    }

    /** Phase 11: the conversation as $user may read it: with the internal notes for staff, without them for the requester. */
    public function conversationFor(User $user): HasMany
    {
        return $this->hasMany(TicketReply::class, 'request_id')->visibleTo($user, $this)->oldest('id');
    }

    /** Full change history (oldest first). */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(RequestStatusLog::class, 'request_id')->oldest('id');
    }

    public function expenditures(): HasMany
    {
        return $this->hasMany(ReceiptExpenditure::class, 'request_id');
    }
}
