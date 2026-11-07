<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring preventive-maintenance schedule (PM Scheduler, Phase 1).
 *
 * frequency_interval = "every N units". Quarterly counts 3 months per unit; custom = every N days.
 * Occurrence n is always counted from start_date (start + n * step), so Jan 31 -> Feb 28 -> Mar 31 never drifts.
 * next_due_date is recomputed on save whenever the dates or the frequency change (Phase 2 will advance it after making a ticket).
 */
class PmSchedule extends Model
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const YEARLY = 'yearly';

    public const CUSTOM = 'custom';

    public const PRIORITY_FCFS = 'first_come_first_served';

    public const PRIORITY_EMERGENCY = 'emergency';

    public const BASIS_FIXED = 'fixed';

    public const BASIS_FLOATING = 'floating';

    protected $fillable = [
        'title', 'description', 'facility_id', 'equipment_id', 'category_id', 'ticket_priority', 'assigned_to',
        'frequency_type', 'frequency_interval', 'start_date', 'end_date', 'next_due_date', 'lead_time_days', 'schedule_basis',
        'is_active', 'created_by', 'anchor_date', 'last_completed_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_due_date' => 'date',
            'anchor_date' => 'date',
            'last_completed_date' => 'date',
            'frequency_interval' => 'integer',
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
            'last_generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PmSchedule $schedule) {
            // Phase B: changing the dates or the frequency by hand drops the floating anchor (the schedule counts from start_date again)
            if ($schedule->exists && $schedule->isDirty(['start_date', 'frequency_type', 'frequency_interval']) && ! $schedule->isDirty('anchor_date')) {
                $schedule->anchor_date = null;
            }

            if (! $schedule->exists || $schedule->isDirty(['start_date', 'end_date', 'frequency_type', 'frequency_interval'])) {
                $schedule->next_due_date = $schedule->firstDueOnOrAfter(now(config('app.timezone'))->toDateString());
            }
        });
    }

    /** @return array<string, string> */
    public static function frequencyLabels(): array
    {
        return [
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::QUARTERLY => 'Quarterly',
            self::YEARLY => 'Yearly',
            self::CUSTOM => 'Custom (every N days)',
        ];
    }

    /** @return array<string, string> */
    public static function priorityLabels(): array
    {
        return [
            self::PRIORITY_FCFS => 'First come, first served',
            self::PRIORITY_EMERGENCY => 'Emergency',
        ];
    }

    /** The n-th occurrence (0 = start_date). */
    public function occurrence(int $n): CarbonImmutable
    {
        $base = $this->anchor_date ?? $this->start_date;   // Phase B: a floating schedule counts from the last completed day
        $start = CarbonImmutable::parse($base->toDateString())->startOfDay();
        $k = $n * max(1, (int) $this->frequency_interval);

        return match ($this->frequency_type) {
            self::WEEKLY => $start->addWeeks($k),
            self::MONTHLY => $start->addMonthsNoOverflow($k),
            self::QUARTERLY => $start->addMonthsNoOverflow($k * 3),
            self::YEARLY => $start->addYearsNoOverflow($k),
            default => $start->addDays($k),   // daily + custom
        };
    }

    /** First occurrence on or after $from (Y-m-d), or null when the schedule has already ended. */
    public function firstDueOnOrAfter(string $from): ?string
    {
        for ($n = 0; $n < 20000; $n++) {
            $date = $this->occurrence($n)->toDateString();

            if ($date >= $from) {
                return ($this->end_date !== null && $date > $this->end_date->toDateString()) ? null : $date;
            }
        }

        return null;
    }

    /** "Every 3 months", "Every week", "Every 10 days"... */
    public function frequencyText(): string
    {
        $n = max(1, (int) $this->frequency_interval);

        [$unit, $times] = match ($this->frequency_type) {
            self::WEEKLY => ['week', 1],
            self::MONTHLY => ['month', 1],
            self::QUARTERLY => ['month', 3],
            self::YEARLY => ['year', 1],
            default => ['day', 1],
        };

        $count = $n * $times;

        return $count === 1 ? "Every {$unit}" : "Every {$count} {$unit}s";
    }

    /** The checklist template: copied into every ticket this schedule makes. */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(PmChecklistItem::class, 'pm_schedule_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isFloating(): bool
    {
        return $this->schedule_basis === self::BASIS_FLOATING;
    }

    /** @return array<string, string> */
    public static function basisLabels(): array
    {
        return [
            self::BASIS_FIXED => 'The original schedule (fixed)',
            self::BASIS_FLOATING => 'The day the work is actually completed (floating)',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'pm_schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}