<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\PmJobRun;
use App\Models\PmSchedule;
use App\Models\RequestStatusLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PM Scheduler, Phase 2: turns due PM schedules into maintenance tickets.
 *
 *  - Due = next_due_date minus lead_time_days is today or earlier.
 *  - Idempotent: the database allows ONE ticket per (schedule, due date), and a run that finds that ticket only moves the schedule on.
 *  - Catch-up: if the job missed days, ONE ticket is made (for the stored due date) and next_due_date jumps to the next FUTURE date.
 *  - Config: school.pm.auto_approve (default true) sends the ticket straight to Approved (and Assigned when the schedule has an assignee).
 *
 * Outcomes of generate(): created | exists | not_due | inactive | ended | no_requester
 */
class PmTicketGenerator
{
    /** @return Collection<int, PmSchedule> the active schedules whose ticket should be made by $today */
    public static function dueSchedules(?string $today = null): Collection
    {
        $today ??= self::today();

        return PmSchedule::query()
            ->with(['facility', 'equipment', 'assignee'])
            ->where('is_active', true)
            ->whereNotNull('next_due_date')
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (PmSchedule $s) => self::leadDate($s) <= $today)
            ->values();
    }

    /** Make the tickets that are due and write one line in the run log. Never stops at the first error. */
    public static function runDue(string $trigger = 'schedule', ?string $today = null, ?User $by = null): PmJobRun
    {
        $today ??= self::today();
        $startedAt = now();
        $checked = $created = $skipped = 0;
        $errors = [];

        foreach (self::dueSchedules($today) as $schedule) {
            $checked++;

            try {
                [$outcome] = self::generate($schedule, $today, false, $by);

                if ($outcome === 'created') {
                    $created++;
                } else {
                    $skipped++;
                }

                if ($outcome === 'no_requester') {
                    $errors[] = "{$schedule->title}: there is no active maintenance staff to file the ticket.";
                }
            } catch (Throwable $e) {
                report($e);
                $errors[] = "{$schedule->title}: {$e->getMessage()}";
            }
        }

        return self::logRun($trigger, $by, $checked, $created, $skipped, $errors, $startedAt);
    }

    /**
     * Make the ticket for ONE schedule's next due date and move the schedule on.
     *
     * @param  bool  $force  ignore the lead time (the "Ticket now" button)
     * @return array{0: string, 1: ?MaintenanceRequest} [outcome, ticket]
     */
    public static function generate(PmSchedule $schedule, ?string $today = null, bool $force = false, ?User $by = null): array
    {
        $today ??= self::today();
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($schedule, $today, $force, $by) {
                    $s = PmSchedule::query()->with(['facility', 'equipment', 'assignee'])->lockForUpdate()->findOrFail($schedule->id);

                    if (! $s->is_active) {
                        return ['inactive', null];
                    }

                    if ($s->next_due_date === null) {
                        return ['ended', null];
                    }

                    $due = $s->next_due_date->toDateString();

                    if (! $force && self::leadDate($s) > $today) {
                        return ['not_due', null];
                    }

                    $ticket = MaintenanceRequest::withTrashed()
                        ->where('pm_schedule_id', $s->id)->whereDate('pm_due_date', $due)->first();
                    $outcome = 'exists';

                    if ($ticket === null) {
                        $requester = self::requester($s);

                        if ($requester === null) {
                            return ['no_requester', null];
                        }

                        $ticket = self::makeTicket($s, $requester, $due, $today);
                        $outcome = 'created';
                    }

                    // Move on to the next FUTURE date (never a date that is already behind us).
                    $from = Carbon::parse(max($today, $due), config('app.timezone'))->addDay()->toDateString();

                    $s->forceFill([
                        'next_due_date' => $s->firstDueOnOrAfter($from),
                        'last_generated_at' => now(),
                    ])->save();

                    if ($outcome === 'created') {
                        AuditLogger::log(
                            AuditLogger::PM_SCHEDULE_CHANGED,
                            "PM ticket {$ticket->ticket_no} created from schedule: {$s->title} (due {$due})",
                            $s,
                            $by,
                            ['ticket_id' => $ticket->id, 'due_date' => $due],
                        );
                    }

                    return [$outcome, $ticket];
                });
            } catch (UniqueConstraintViolationException $e) {
                // Either two people got the same ticket number at the same instant (try again),
                // or the ticket for this due date was made a moment ago by someone else (next loop finds it).
                if (++$attempts >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** "[PM] Aircon Cleaning - Q4 2026" */
    public static function title(PmSchedule $schedule, Carbon $due): string
    {
        $label = match ($schedule->frequency_type) {
            PmSchedule::QUARTERLY => 'Q'.$due->quarter.' '.$due->year,
            PmSchedule::MONTHLY => $due->format('M Y'),
            PmSchedule::YEARLY => (string) $due->year,
            default => $due->format('M j, Y'),
        };

        return "[PM] {$schedule->title} - {$label}";
    }

    /** Write one line in the run log (daily job, command, or the "Ticket now" button). */
    public static function logRun(string $trigger, ?User $by, int $checked, int $created, int $skipped, array $errors, ?Carbon $startedAt = null): PmJobRun
    {
        $run = PmJobRun::create([
            'trigger' => $trigger,
            'ran_by' => $by?->id,
            'started_at' => $startedAt ?? now(),
            'finished_at' => now(),
            'schedules_checked' => $checked,
            'tickets_created' => $created,
            'skipped' => $skipped,
            'error_count' => count($errors),
            'errors' => $errors === [] ? null : $errors,
        ]);

        Log::info('PM ticket generation', [
            'trigger' => $trigger, 'checked' => $checked, 'created' => $created, 'skipped' => $skipped, 'errors' => $errors,
        ]);

        return $run;
    }

    // ---------------------------------------------------------------------------------------------

    private static function today(): string
    {
        return now(config('app.timezone'))->toDateString();
    }

    /** The day the ticket should be made: due date minus lead time. */
    private static function leadDate(PmSchedule $s): string
    {
        return $s->next_due_date->copy()->subDays((int) $s->lead_time_days)->toDateString();
    }

    private static function makeTicket(PmSchedule $s, User $requester, string $due, string $today): MaintenanceRequest
    {
        $tz = config('app.timezone');
        $dueDay = Carbon::parse($due, $tz);
        $title = self::title($s, $dueDay);
        $emergency = $s->ticket_priority === PmSchedule::PRIORITY_EMERGENCY;

        $location = $s->facility?->name ?? 'Facility';
        if ($s->equipment) {
            $location .= ' - '.$s->equipment->name;
        }

        $lines = [$title, ''];
        if (filled($s->description)) {
            $lines[] = trim((string) $s->description);
            $lines[] = '';
        }
        $lines[] = 'Scheduled preventive maintenance ('.$s->frequencyText().'). Due: '.$dueDay->format('M j, Y').'.';
        if ($due < $today) {
            $lines[] = 'Earlier missed dates were skipped. Only this one ticket was made.';
        }

        $ticket = MaintenanceRequest::create([
            'requester_id' => $requester->id,
            'department_id' => $requester->department_id ?? self::maintenanceDepartment()->id,
            'category_id' => $s->category_id ?: Category::otherFor(Category::TYPE_EQUIPMENT)->id,
            'request_type' => null,
            'maintenance_type' => 'preventive',
            'location' => mb_substr($location, 0, 255),
            'description' => implode("\n", $lines),
            'reason' => 'Scheduled preventive maintenance ('.$s->frequencyText().')',
            'needed_by' => $due,
            'due_at' => Carbon::parse(max($due, $today), $tz)->endOfDay(),
            'is_emergency' => $emergency,
            'emergency_reason' => $emergency ? 'Scheduled preventive maintenance marked as emergency priority.' : null,
            'emergency_flagged_at' => $emergency ? now() : null,
            'status' => TicketStatus::Submitted,
            'pm_schedule_id' => $s->id,
            'pm_due_date' => $due,
        ]);

        $ticket->logEvent(
            RequestStatusLog::ACTION_STATUS, null,
            "Created automatically by the PM Scheduler: {$s->title}.",
            null, TicketStatus::Submitted,
            ['pm_schedule_id' => $s->id, 'pm_due_date' => $due],
        );

        self::copyChecklist($s, $ticket);

        if ($emergency) {
            $ticket->logEvent(RequestStatusLog::ACTION_EMERGENCY, null, $ticket->emergency_reason, meta: ['flagged' => true]);
        }

        if (config('school.pm.auto_approve', true)) {
            $ticket = TicketWorkflow::transition($ticket, TicketStatus::Approved, null, 'Pre-approved: scheduled preventive maintenance.');

            $assignee = $s->assignee;
            if ($assignee && $assignee->is_active && $assignee->isMaintenanceTeam()) {
                $ticket = TicketWorkflow::assign($ticket, $assignee, null, 'Assigned by the PM schedule.');
            }
        }

        return $ticket;
    }

    /** Copy the schedule's checklist template onto the new ticket. */
    private static function copyChecklist(PmSchedule $s, MaintenanceRequest $ticket): void
    {
        foreach ($s->checklistItems as $i => $item) {
            $ticket->pmChecklist()->create(['label' => $item->label, 'sort_order' => $i]);
        }
    }

    /** Who files the ticket: the schedule's creator, its assignee, or the first active maintenance admin. */
    private static function requester(PmSchedule $s): ?User
    {
        foreach ([$s->created_by, $s->assigned_to] as $id) {
            $user = $id ? User::find($id) : null;

            if ($user && $user->is_active && $user->isMaintenanceTeam()) {
                return $user;
            }
        }

        return User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', Role::maintenanceSlugs()))
            ->orderBy('id')->first();
    }

    private static function maintenanceDepartment(): Department
    {
        return Department::firstOrCreate(['code' => 'MNT'], ['name' => 'Maintenance Office']);
    }
}