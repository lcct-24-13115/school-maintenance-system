<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Models\RequestStatusLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PM integration, Phase B: completing a preventive-maintenance ticket.
 *
 * Records the completed date, the technician, the notes and the checklist results, moves the ticket to Work Completed
 * (through TicketWorkflow, so the history, audit and email rules stay the same) and sets the schedule's next due date:
 *
 *  - fixed    the original schedule is kept. The generator already moved next_due_date on when it made this ticket.
 *  - floating the next due date is counted from the day the work was REALLY completed (anchor_date).
 *             Skipped when a newer ticket of the schedule already exists (that ticket keeps its date).
 *
 * school.pm.require_note_for_unchecked (default true): a checklist line that is not ticked needs a note saying why.
 */
class PmCompletion
{
    /**
     * @param  array{completed_date: string, technician_id?: int|string|null, notes?: ?string, checklist?: array<int|string, array{done?: mixed, note?: ?string}>}  $data
     * @return array{ticket: MaintenanceRequest, schedule: ?PmSchedule, basis: string}  basis: fixed | floating | floating_skipped | none
     */
    public static function complete(MaintenanceRequest $ticket, User $by, array $data): array
    {
        $tz = config('app.timezone');

        if (! $by->is_active || ! $by->can_do('work')) {
            throw new DomainException('Only maintenance staff can complete a task.');
        }

        if (! $ticket->isPmGenerated()) {
            throw new DomainException('This is not a preventive maintenance ticket.');
        }

        if ($ticket->status !== TicketStatus::InProgress) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\". Accept the task first: only an In Progress PM ticket can be completed.");
        }

        // ---- completed date: not in the future, not before the ticket was made ----
        $completed = Carbon::parse((string) ($data['completed_date'] ?? ''), $tz)->startOfDay();
        $today = now($tz)->startOfDay();

        if ($completed->greaterThan($today)) {
            throw new DomainException('The completed date cannot be in the future.');
        }
        if ($completed->lessThan($ticket->submitted_at->copy()->timezone($tz)->startOfDay())) {
            throw new DomainException('The completed date cannot be before the ticket was made ('.$ticket->submitted_at->copy()->timezone($tz)->format('M j, Y').').');
        }

        // ---- technician: active maintenance staff; default = the assignee, then the person completing ----
        $technician = User::query()->find($data['technician_id'] ?? $ticket->assigned_to ?? $by->id);

        if (! $technician || ! $technician->is_active || ! $technician->isMaintenanceTeam()) {
            throw new DomainException('The technician must be an active maintenance staff member.');
        }

        // ---- checklist results ----
        $input = (array) ($data['checklist'] ?? []);
        $needNote = (bool) config('school.pm.require_note_for_unchecked', true);
        $rows = [];
        $missing = [];
        $undone = [];

        foreach ($ticket->pmChecklist()->get() as $item) {
            $line = (array) ($input[$item->id] ?? []);
            $done = filter_var($line['done'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $note = trim((string) ($line['note'] ?? ''));

            if (! $done) {
                $undone[] = $note === '' ? $item->label : "{$item->label} ({$note})";

                if ($needNote && $note === '') {
                    $missing[] = $item->label;
                }
            }

            $rows[] = [$item, $done, $note === '' ? null : mb_substr($note, 0, 500)];
        }

        if ($missing !== []) {
            throw new DomainException('Tick these tasks, or write why they were not done: '.implode('; ', $missing).'.');
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        $doneCount = count(array_filter($rows, fn (array $r) => $r[1]));
        $total = count($rows);

        $result = ['ticket' => $ticket, 'schedule' => null, 'basis' => 'none'];

        DB::transaction(function () use ($ticket, $by, $completed, $technician, $rows, $notes, $undone, $doneCount, $total, &$result) {
            foreach ($rows as [$item, $done, $note]) {
                $item->forceFill(['is_done' => $done, 'note' => $note])->save();
            }

            $ticket->forceFill([
                'pm_completed_date' => $completed->toDateString(),
                'pm_technician_id' => $technician->id,
                'pm_notes' => $notes === '' ? null : $notes,
            ])->save();

            // The schedule moves BEFORE the status change: the status change queues the emails, so it must be the last step.
            [$schedule, $basis] = self::advanceSchedule($ticket, $completed, $by);

            $problems = $undone === [] ? null : 'Not done: '.implode('; ', $undone);
            TicketWorkflow::markWorkCompleted($ticket, $by, $problems);

            $summary = sprintf('PM completed on %s by %s.', $completed->format('M j, Y'), $technician->name);
            if ($total > 0) {
                $summary .= " {$doneCount} of {$total} checklist tasks done.";
            }
            if ($schedule?->next_due_date !== null) {
                $summary .= ' Next due: '.$schedule->next_due_date->format('M j, Y').($basis === 'floating' ? ' (counted from the completed date).' : '.');
            }

            $ticket->logEvent(RequestStatusLog::ACTION_NOTE, $by, $summary, meta: [
                'pm_completed' => true, 'completed_date' => $completed->toDateString(), 'technician_id' => $technician->id,
            ]);

            $result = ['ticket' => $ticket->refresh(), 'schedule' => $schedule, 'basis' => $basis];
        });

        return $result;
    }

    /**
     * Record the completion on the schedule and, for a floating schedule, count the next due date from the completed day.
     *
     * @return array{0: ?PmSchedule, 1: string}
     */
    private static function advanceSchedule(MaintenanceRequest $ticket, Carbon $completed, User $by): array
    {
        $schedule = $ticket->pm_schedule_id ? PmSchedule::query()->lockForUpdate()->find($ticket->pm_schedule_id) : null;

        if ($schedule === null) {
            return [null, 'none'];   // the schedule was deleted: nothing to move
        }

        $schedule->last_completed_date = $completed->toDateString();
        $basis = 'fixed';

        if ($schedule->isFloating() && $schedule->is_active) {
            // A newer ticket of this schedule exists (the generator ran ahead): it keeps its own date.
            $newer = MaintenanceRequest::withTrashed()
                ->where('pm_schedule_id', $schedule->id)
                ->whereDate('pm_due_date', '>', $ticket->pm_due_date?->toDateString() ?? $completed->toDateString())
                ->exists();

            if ($newer) {
                $basis = 'floating_skipped';
            } else {
                $today = now(config('app.timezone'))->toDateString();

                $schedule->anchor_date = $completed->toDateString();
                $schedule->next_due_date = $schedule->firstDueOnOrAfter(max($completed->copy()->addDay()->toDateString(), $today));
                $basis = 'floating';
            }
        }

        $schedule->saveQuietly();   // quietly: the saving hook would recount next_due_date from today and undo the floating date

        AuditLogger::log(
            AuditLogger::PM_SCHEDULE_CHANGED,
            "PM ticket {$ticket->ticket_no} completed on {$completed->toDateString()}: {$schedule->title}"
                .($schedule->next_due_date ? " (next due {$schedule->next_due_date->toDateString()}, {$basis})" : ' (schedule ended)'),
            $schedule,
            $by,
            ['ticket_id' => $ticket->id, 'completed_date' => $completed->toDateString(), 'basis' => $basis],
        );

        return [$schedule, $basis];
    }
}