<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Maintenance Calendar, Phase 1: builds the events of the calendar. NOTHING is saved here.
 *
 *  - ticket events: the PM tickets that already exist (one per schedule per due date), on their pm_due_date.
 *  - projected events: the next occurrences of the ACTIVE schedules that have no ticket yet, up to 12 months ahead.
 *    They start from next_due_date, so the past is never projected, and an occurrence that already has a ticket
 *    (even a deleted one) is skipped, the same rule PmTicketGenerator uses.
 *
 * Status colours: upcoming (blue) = projected, no ticket yet | open (yellow) = ticket still pending |
 * completed (green) = Work Completed / Closed | overdue (red) = ticket still pending and its PM due date is before today |
 * cancelled (grey) = Cancelled / Disapproved.
 */
class MaintenanceCalendar
{
    public const UPCOMING = 'upcoming';

    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    public const OVERDUE = 'overdue';

    public const CANCELLED = 'cancelled';

    public const HORIZON_MONTHS = 12;

    public const MAX_RANGE_DAYS = 400;

    /** @return array<string, array{label: string, color: string, text: string}> key = status used by the filter and the legend */
    public static function statuses(): array
    {
        return [
            self::UPCOMING => ['label' => 'Upcoming', 'color' => '#2563eb', 'text' => '#ffffff'],
            self::OPEN => ['label' => 'Open / In Progress', 'color' => '#facc15', 'text' => '#1f2937'],
            self::COMPLETED => ['label' => 'Completed', 'color' => '#16a34a', 'text' => '#ffffff'],
            self::OVERDUE => ['label' => 'Overdue', 'color' => '#dc2626', 'text' => '#ffffff'],
            self::CANCELLED => ['label' => 'Cancelled', 'color' => '#6b7280', 'text' => '#ffffff'],
        ];
    }

    /**
     * @param  array{facility_id?: ?int, equipment_id?: ?int, category_id?: ?int, assignee_id?: ?int, status?: ?string}  $filters
     * @return list<array<string, mixed>> FullCalendar event objects
     */
    public static function events(User $user, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $f = array_merge([
            'facility_id' => null, 'equipment_id' => null, 'category_id' => null, 'assignee_id' => null, 'status' => null,
        ], $filters);

        $today = now(config('app.timezone'))->toDateString();

        $events = array_merge(
            self::ticketEvents($user, $from, $to, $f, $today),
            self::projectedEvents($from, $to, $f, $today),
        );

        usort($events, fn (array $a, array $b) => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private static function ticketEvents(User $user, CarbonImmutable $from, CarbonImmutable $to, array $f, string $today): array
    {
        $tickets = MaintenanceRequest::query()
            ->visibleTo($user)
            ->whereNotNull('pm_schedule_id')
            ->whereDate('pm_due_date', '>=', $from->toDateString())
            ->whereDate('pm_due_date', '<=', $to->toDateString())
            ->with(['pmSchedule.facility:id,name', 'pmSchedule.equipment:id,name', 'category:id,name', 'assignee:id,name'])
            ->when($f['facility_id'], fn ($q, $id) => $q->whereHas('pmSchedule', fn ($s) => $s->where('facility_id', $id)))
            ->when($f['equipment_id'], fn ($q, $id) => $q->whereHas('pmSchedule', fn ($s) => $s->where('equipment_id', $id)))
            ->when($f['category_id'], fn ($q, $id) => $q->where('category_id', $id))
            ->when($f['assignee_id'], fn ($q, $id) => $q->where('assigned_to', $id))
            ->get();

        $events = [];

        foreach ($tickets as $ticket) {
            $schedule = $ticket->pmSchedule;

            if ($schedule === null) {
                continue;
            }

            $bucket = self::bucket($ticket, $today);

            if ($f['status'] !== null && $f['status'] !== $bucket) {
                continue;
            }

            $date = $ticket->pm_due_date->toDateString();

            $events[] = self::event('ticket-'.$ticket->id, $schedule->title, $date, $bucket, [
                'kind' => 'ticket',
                'ticket_no' => $ticket->ticket_no,
                'ticket_status' => $ticket->status->label(),
                'facility' => $schedule->facility?->name,
                'equipment' => $schedule->equipment?->name,
                'category' => $ticket->category?->name,
                'assignee' => $ticket->assignee?->name ?? 'Unassigned',
                'frequency' => $schedule->frequencyText(),
                'due_date' => $date,
                'ticket_url' => route('tickets.show', $ticket->id, false),
                'schedule_url' => route('admin.pm-schedules.edit', $schedule->id, false),
            ]);
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private static function projectedEvents(CarbonImmutable $from, CarbonImmutable $to, array $f, string $today): array
    {
        if ($f['status'] !== null && $f['status'] !== self::UPCOMING) {
            return [];
        }

        $horizon = CarbonImmutable::parse($today)->addMonthsNoOverflow(self::HORIZON_MONTHS)->toDateString();
        $first = $from->toDateString();
        $last = min($to->toDateString(), $horizon);

        if ($first > $last) {
            return [];
        }

        $schedules = PmSchedule::query()
            ->with(['facility:id,name', 'equipment:id,name', 'category:id,name', 'assignee:id,name'])
            ->where('is_active', true)
            ->whereNotNull('next_due_date')
            ->when($f['facility_id'], fn ($q, $id) => $q->where('facility_id', $id))
            ->when($f['equipment_id'], fn ($q, $id) => $q->where('equipment_id', $id))
            ->when($f['category_id'], fn ($q, $id) => $q->where('category_id', $id))
            ->when($f['assignee_id'], fn ($q, $id) => $q->where('assigned_to', $id))
            ->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        // occurrences that already have a ticket (deleted ones too) are not projected again
        $taken = MaintenanceRequest::withTrashed()
            ->whereIn('pm_schedule_id', $schedules->pluck('id'))
            ->whereDate('pm_due_date', '>=', $first)
            ->whereDate('pm_due_date', '<=', $last)
            ->get(['pm_schedule_id', 'pm_due_date'])
            ->mapWithKeys(fn ($t) => [$t->pm_schedule_id.'|'.$t->pm_due_date->toDateString() => true])
            ->all();

        $events = [];

        foreach ($schedules as $schedule) {
            $begin = max($first, $schedule->next_due_date->toDateString());
            $end = $schedule->end_date !== null ? min($last, $schedule->end_date->toDateString()) : $last;

            for ($n = 0; $n < 20000; $n++) {
                $date = $schedule->occurrence($n)->toDateString();

                if ($date > $end) {
                    break;
                }

                if ($date < $begin || isset($taken[$schedule->id.'|'.$date])) {
                    continue;
                }

                $events[] = self::event('pm-'.$schedule->id.'-'.$date, $schedule->title, $date, self::UPCOMING, [
                    'kind' => 'projected',
                    'ticket_no' => null,
                    'ticket_status' => null,
                    'facility' => $schedule->facility?->name,
                    'equipment' => $schedule->equipment?->name,
                    'category' => $schedule->category?->name,
                    'assignee' => $schedule->assignee?->name ?? 'Unassigned',
                    'frequency' => $schedule->frequencyText(),
                    'due_date' => $date,
                    'ticket_url' => null,
                    'schedule_url' => route('admin.pm-schedules.edit', $schedule->id, false),
                ]);
            }
        }

        return $events;
    }

    private static function bucket(MaintenanceRequest $ticket, string $today): string
    {
        $status = $ticket->status;

        if ($status->isCompleted()) {
            return self::COMPLETED;
        }

        if (in_array($status, [TicketStatus::Cancelled, TicketStatus::Disapproved], true)) {
            return self::CANCELLED;
        }

        return $ticket->pm_due_date->toDateString() < $today ? self::OVERDUE : self::OPEN;
    }

    /** @return array<string, mixed> */
    private static function event(string $id, string $title, string $date, string $bucket, array $props): array
    {
        $style = self::statuses()[$bucket];

        return [
            'id' => $id,
            'title' => $title,
            'start' => $date,
            'allDay' => true,
            'backgroundColor' => $style['color'],
            'borderColor' => $style['color'],
            'textColor' => $style['text'],
            'classNames' => ['pmcal-'.$bucket],
            'extendedProps' => $props + [
                'status' => $bucket,
                'status_label' => $style['label'],
                'due_date_label' => CarbonImmutable::parse($date)->format('M j, Y'),
            ],
        ];
    }
}