<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * PM compliance (PM E3): did the preventive-maintenance work get done on time?
 *
 * Counts the PM tickets (tickets that have a pm_due_date) whose due date is inside the chosen range.
 * Every PM ticket falls in exactly one bucket (CONFIRM WITH CLIENT, see docs/OPEN_QUESTIONS.md #70-72):
 *
 *   on_time    Work Completed / Closed, and the work was done on or before the PM due date
 *   late       Work Completed / Closed, but done after the PM due date
 *   overdue    still pending and the PM due date is before today
 *   open       still pending and the PM due date is today or later (not due yet: left out of the rate)
 *   cancelled  Cancelled / Disapproved (left out of everything, shown only as a count)
 *
 * Compliance rate = on_time / (on_time + late + overdue), as a percent. Empty when nothing is left to count.
 * The "done" day is pm_completed_date (the PM form), else the day the ticket reached Work Completed.
 * Every query starts from visibleTo(), like every other ticket list and report.
 */
class PmCompliance
{
    public const ON_TIME = 'on_time';

    public const LATE = 'late';

    public const OVERDUE = 'overdue';

    public const OPEN = 'open';

    public const CANCELLED = 'cancelled';

    private const CHUNK = 500;

    public function __construct(
        private User $user,
        private ?string $from = null,
        private ?string $to = null,
        private ?int $facilityId = null,
    ) {}

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::ON_TIME => 'On time',
            self::LATE => 'Done late',
            self::OVERDUE => 'Overdue',
            self::OPEN => 'Not due yet',
            self::CANCELLED => 'Cancelled',
        ];
    }

    public function tickets(): Builder
    {
        return MaintenanceRequest::query()
            ->visibleTo($this->user)
            ->whereNotNull('pm_due_date')
            ->when($this->from, fn ($q, $d) => $q->whereDate('pm_due_date', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->whereDate('pm_due_date', '<=', $d))
            ->when($this->facilityId, fn ($q, $id) => $q->whereHas('pmSchedule', fn ($s) => $s->where('facility_id', $id)));
    }

    /** The day the PM work was really done, or null when it is not done. */
    public static function doneDate(MaintenanceRequest $t): ?string
    {
        if (! $t->status->isCompleted()) {
            return null;
        }

        $tz = config('app.timezone');

        return $t->pm_completed_date?->toDateString()
            ?? $t->completed_at?->copy()->timezone($tz)->toDateString()
            ?? $t->closed_at?->copy()->timezone($tz)->toDateString();
    }

    public static function bucket(MaintenanceRequest $t, string $today): string
    {
        if (in_array($t->status, [TicketStatus::Cancelled, TicketStatus::Disapproved], true)) {
            return self::CANCELLED;
        }

        $due = $t->pm_due_date->toDateString();

        if ($t->status->isCompleted()) {
            $done = self::doneDate($t);

            return $done !== null && $done > $due ? self::LATE : self::ON_TIME;
        }

        return $due < $today ? self::OVERDUE : self::OPEN;
    }

    /** @return int|float|null percent with one decimal, null when there is nothing to count */
    public static function rate(int $onTime, int $late, int $overdue): int|float|null
    {
        $base = $onTime + $late + $overdue;

        return $base === 0 ? null : round($onTime / $base * 100, 1);
    }

    /**
     * One pass over the tickets.
     *
     * @return array{summary: array<string, mixed>, by_facility: list<array<string, mixed>>, by_month: list<array<string, mixed>>, overdue: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function analyse(bool $withRows = true): array
    {
        $tz = config('app.timezone');
        $today = now($tz)->toDateString();
        $soon = now($tz)->addDays(7)->toDateString();

        $summary = $this->blank() + ['due_soon' => 0, 'days_late_total' => 0];
        $facilities = [];
        $months = [];
        $rows = [];

        $this->tickets()
            ->with(['pmSchedule:id,title,facility_id,equipment_id', 'pmSchedule.facility:id,name', 'pmSchedule.equipment:id,name', 'assignee:id,name', 'pmTechnician:id,name'])
            ->chunkById(self::CHUNK, function ($tickets) use ($today, $soon, $withRows, &$summary, &$facilities, &$months, &$rows) {
                /** @var MaintenanceRequest $t */
                foreach ($tickets as $t) {
                    $bucket = self::bucket($t, $today);
                    $due = $t->pm_due_date->toDateString();
                    $done = self::doneDate($t);

                    $days = match ($bucket) {
                        self::LATE => $done !== null ? (int) $t->pm_due_date->diffInDays($done, false) : 0,
                        self::OVERDUE => (int) $t->pm_due_date->diffInDays($today, false),
                        default => 0,
                    };

                    $facility = $t->pmSchedule?->facility?->name ?? 'Schedule deleted';
                    $monthKey = substr($due, 0, 7);

                    $facilities[$facility] ??= $this->blank();
                    $months[$monthKey] ??= $this->blank();

                    $summary[$bucket]++;
                    $facilities[$facility][$bucket]++;
                    $months[$monthKey][$bucket]++;

                    if ($bucket === self::LATE) {
                        $summary['days_late_total'] += $days;
                    }
                    if ($bucket === self::OPEN && $due <= $soon) {
                        $summary['due_soon']++;
                    }

                    if ($withRows) {
                        $rows[] = [
                            'id' => $t->id,
                            'ticket_no' => $t->ticket_no,
                            'schedule' => $t->pmSchedule?->title ?? '(schedule deleted)',
                            'facility' => $facility,
                            'equipment' => $t->pmSchedule?->equipment?->name,
                            'assignee' => $t->assignee?->name,
                            'technician' => $t->pmTechnician?->name,
                            'status' => $t->status->label(),
                            'due' => $due,
                            'done' => $done,
                            'bucket' => $bucket,
                            'days' => $days,
                        ];
                    }
                }
            });

        $late = $summary[self::LATE];
        $summary['counted'] = $summary[self::ON_TIME] + $late + $summary[self::OVERDUE] + $summary[self::OPEN];
        $summary['rate'] = self::rate($summary[self::ON_TIME], $late, $summary[self::OVERDUE]);
        $summary['avg_days_late'] = $late > 0 ? round($summary['days_late_total'] / $late, 1) : null;

        $byFacility = [];
        ksort($facilities);
        foreach ($facilities as $name => $box) {
            $byFacility[] = $this->decorate(['label' => $name] + $box);
        }

        $byMonth = [];
        ksort($months);
        foreach ($months as $key => $box) {
            $byMonth[] = $this->decorate(['label' => date('M Y', strtotime($key.'-01')), 'key' => $key] + $box);
        }

        // worst first: the longest overdue on top
        $overdue = array_values(array_filter($rows, fn ($r) => $r['bucket'] === self::OVERDUE));
        usort($overdue, fn ($a, $b) => [$b['days'], $a['due']] <=> [$a['days'], $b['due']]);

        usort($rows, fn ($a, $b) => [$b['due'], $a['ticket_no']] <=> [$a['due'], $b['ticket_no']]);

        return [
            'summary' => $summary,
            'by_facility' => $byFacility,
            'by_month' => $byMonth,
            'overdue' => $overdue,
            'rows' => $rows,
        ];
    }

    /**
     * The three dashboard cards (President / Finance). Empty for anyone who may not open the report.
     * Overdue and due-soon cover all dates; the rate covers the last $days days of PM due dates.
     *
     * @return array<string, array{count: int, url: string, display?: string}>
     */
    public static function dashboardCards(User $user, int $days = 90): array
    {
        if (! $user->canViewPmReports()) {
            return [];
        }

        $url = route('pm-reports.index');

        $all = (new self($user))->analyse(false)['summary'];
        $recent = (new self($user, now(config('app.timezone'))->subDays($days - 1)->toDateString(), now(config('app.timezone'))->toDateString()))
            ->analyse(false)['summary'];

        return [
            'PM overdue' => ['count' => $all[self::OVERDUE], 'url' => $url],
            'PM due in 7 days' => ['count' => $all['due_soon'], 'url' => $url],
            "PM on-time rate ({$days} days)" => [
                'count' => (int) round((float) ($recent['rate'] ?? 0)),
                'url' => $url,
                'display' => $recent['rate'] === null ? '—' : number_format((float) $recent['rate'], 1).'%',
            ],
        ];
    }

    /** @return array<string, int> */
    private function blank(): array
    {
        return [self::ON_TIME => 0, self::LATE => 0, self::OVERDUE => 0, self::OPEN => 0, self::CANCELLED => 0];
    }

    /** @param  array<string, mixed>  $box */
    private function decorate(array $box): array
    {
        $box['counted'] = $box[self::ON_TIME] + $box[self::LATE] + $box[self::OVERDUE] + $box[self::OPEN];
        $box['rate'] = self::rate($box[self::ON_TIME], $box[self::LATE], $box[self::OVERDUE]);

        return $box;
    }
}
