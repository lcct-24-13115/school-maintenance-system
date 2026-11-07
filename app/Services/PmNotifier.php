<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Mail\TicketNotificationMail;
use App\Models\MaintenanceRequest;
use App\Models\RequestStatusLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\FormActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * PM integration, Phase C: alerts for preventive-maintenance tickets, in the app (bell) AND by email.
 *
 *  newTickets   a new PM ticket was made                          -> the assignee (or, when nobody is assigned, the maintenance staff)
 *  reminders    due in school.pm.reminder_days_before days        -> the assignee (or the maintenance staff)
 *  overdue      the due date passed: marked overdue               -> the assignee + the maintenance staff
 *  escalate     overdue for school.pm.escalate_after_days days    -> the supervisor (school.pm.supervisor_roles) + the maintenance staff
 *
 * Every alert is sent ONCE per ticket: the ticket row remembers it (pm_notified_at, pm_reminded_at, pm_overdue_at,
 * pm_escalated_at) and a ticket is "claimed" with one atomic UPDATE before anything is sent, so two runs at the same
 * moment cannot send twice. Only open tickets are considered (a completed, closed or cancelled one is left alone).
 * The ticket's STATUS is never changed here: an overdue PM ticket must stay workable so it can still be completed.
 * Nothing here throws; one bad ticket or address never stops the others.
 */
class PmNotifier
{
    public static function reminderDays(): int
    {
        return max(0, (int) config('school.pm.reminder_days_before', 3));
    }

    public static function escalateAfterDays(): int
    {
        return max(0, (int) config('school.pm.escalate_after_days', 2));
    }

    /** @return array{new: int, reminders: int, overdue: int, escalated: int} */
    public static function runAll(?string $today = null, bool $dry = false): array
    {
        return [
            'new' => self::newTickets($dry),
            'reminders' => self::reminders($today, $dry),
            'overdue' => self::overdue($today, $dry),
            'escalated' => self::escalate($today, $dry),   // after overdue(): an escalated ticket is always marked overdue first
        ];
    }

    public static function newTickets(bool $dry = false): int
    {
        $tickets = self::open()->whereNull('pm_notified_at')->orderBy('id')->get();

        if ($dry) {
            return $tickets->count();
        }

        $sent = 0;

        foreach ($tickets as $t) {
            self::guard(function () use ($t, &$sent) {
                if (! self::claim($t, 'pm_notified_at')) {
                    return;
                }

                self::deliver($t, 'pm_new', 'New preventive maintenance ticket',
                    'A preventive maintenance ticket is ready. Due '.$t->pm_due_date->format('M j, Y').'.', self::doers($t));
                $sent++;
            });
        }

        return $sent;
    }

    public static function reminders(?string $today = null, bool $dry = false): int
    {
        $days = self::reminderDays();

        if ($days === 0) {
            return 0;
        }

        $day = self::day($today);

        $tickets = self::open()->whereNull('pm_reminded_at')
            ->whereDate('pm_due_date', '>=', $day->toDateString())
            ->whereDate('pm_due_date', '<=', $day->copy()->addDays($days)->toDateString())
            ->whereDate('submitted_at', '<', $day->toDateString())   // a ticket made today already had its "new ticket" alert
            ->orderBy('pm_due_date')->get();

        if ($dry) {
            return $tickets->count();
        }

        $sent = 0;

        foreach ($tickets as $t) {
            self::guard(function () use ($t, $day, &$sent) {
                if (! self::claim($t, 'pm_reminded_at')) {
                    return;
                }

                $left = self::daysBetween($day, $t->pm_due_date);
                $when = $left === 0 ? 'today' : 'in '.$left.' '.Str::plural('day', $left);

                self::deliver($t, 'pm_reminder', 'Preventive maintenance due '.$when,
                    'This preventive maintenance is due '.$when.' ('.$t->pm_due_date->format('M j, Y').').', self::doers($t));
                $sent++;
            });
        }

        return $sent;
    }

    /** Mark the open PM tickets whose due date has passed as overdue, write it in the history and alert the staff. */
    public static function overdue(?string $today = null, bool $dry = false): int
    {
        $day = self::day($today);

        $tickets = self::open()->whereNull('pm_overdue_at')
            ->whereDate('pm_due_date', '<', $day->toDateString())
            ->orderBy('pm_due_date')->get();

        if ($dry) {
            return $tickets->count();
        }

        $sent = 0;

        foreach ($tickets as $t) {
            self::guard(function () use ($t, $day, &$sent) {
                if (! self::claim($t, 'pm_overdue_at')) {
                    return;
                }

                $late = self::daysBetween($t->pm_due_date, $day);
                $text = 'Preventive maintenance is overdue: it was due '.$t->pm_due_date->format('M j, Y').' ('.$late.' '.Str::plural('day', $late).' ago).';

                $t->logEvent(RequestStatusLog::ACTION_NOTE, null, $text, meta: ['pm_overdue' => true, 'days_overdue' => $late]);

                self::deliver($t, 'pm_overdue', 'Preventive maintenance OVERDUE', $text, self::doers($t)->merge(self::admins()));
                $sent++;
            });
        }

        return $sent;
    }

    /** Tell the supervisor about a PM ticket that stayed overdue for escalate_after_days days. The status is NOT changed. */
    public static function escalate(?string $today = null, bool $dry = false): int
    {
        $after = self::escalateAfterDays();

        if ($after === 0) {
            return 0;
        }

        $day = self::day($today);

        $tickets = self::open()->whereNull('pm_escalated_at')
            ->whereDate('pm_due_date', '<=', $day->copy()->subDays($after)->toDateString())
            ->orderBy('pm_due_date')->get();

        if ($dry) {
            return $tickets->count();
        }

        $sent = 0;

        foreach ($tickets as $t) {
            self::guard(function () use ($t, $day, &$sent) {
                if (! self::claim($t, 'pm_escalated_at')) {
                    return;
                }

                $late = self::daysBetween($t->pm_due_date, $day);
                $text = 'Preventive maintenance is '.$late.' '.Str::plural('day', $late).' overdue (due '.$t->pm_due_date->format('M j, Y').') and was escalated to the supervisor.';

                $t->logEvent(RequestStatusLog::ACTION_NOTE, null, $text, meta: ['pm_escalated' => true, 'days_overdue' => $late]);

                self::deliver($t, 'pm_escalated', 'Preventive maintenance escalated', $text, self::supervisors()->merge(self::admins()));
                $sent++;
            });
        }

        return $sent;
    }

    // ---------------------------------------------------------------------------------------------

    /** @return Builder<MaintenanceRequest> open PM tickets: made by the scheduler and still not completed / closed / cancelled */
    private static function open(): Builder
    {
        return MaintenanceRequest::query()
            ->with(['requester', 'department', 'assignee'])
            ->whereNotNull('pm_due_date')
            ->whereIn('status', TicketStatus::pendingValues());
    }

    /** One atomic UPDATE: true only for the run that set the column first. */
    private static function claim(MaintenanceRequest $ticket, string $column): bool
    {
        return MaintenanceRequest::query()->whereKey($ticket->getKey())->whereNull($column)->update([$column => now()]) === 1;
    }

    private static function day(?string $today): Carbon
    {
        return Carbon::parse($today ?: now(config('app.timezone'))->toDateString(), config('app.timezone'))->startOfDay();
    }

    private static function daysBetween(Carbon $from, Carbon $to): int
    {
        return abs((int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()));
    }

    /** The person who must do the work: the assignee, or every maintenance staff member when nobody is assigned. */
    private static function doers(MaintenanceRequest $ticket): Collection
    {
        $assignee = $ticket->assignee;

        return $assignee && $assignee->is_active ? collect([$assignee]) : self::admins();
    }

    private static function admins(): Collection
    {
        return self::withRole(Role::MAINTENANCE_ADMIN);
    }

    /** CONFIRM WITH CLIENT: school.pm.supervisor_roles (default: the President). */
    private static function supervisors(): Collection
    {
        $users = collect();

        foreach ((array) config('school.pm.supervisor_roles', (array) config('school.workflow.authorizers', ['president'])) as $slug) {
            $users = $users->merge(self::withRole((string) $slug));
        }

        return $users;
    }

    private static function withRole(string $slug): Collection
    {
        return User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', $slug))->get();
    }

    /** The bell (database + live) and one queued email per person. Never to someone who may not see the ticket. */
    private static function deliver(MaintenanceRequest $t, string $event, string $headline, string $intro, Collection $users): void
    {
        $users = $users->filter()->unique('id')->filter(fn (User $u) => $u->is_active && $t->isVisibleTo($u));

        if ($users->isEmpty()) {
            return;
        }

        $url = route('tickets.show', $t);

        try {
            Notification::send($users, new FormActivity([
                'event' => $event,
                'form_type' => 'ticket',
                'form_label' => MaintenanceRequest::LABEL,
                'form_id' => $t->id,
                'reference_no' => $t->ticket_no,
                'requester_name' => $t->requester?->name,
                'department' => $t->department?->name,
                'location' => $t->location,
                'urgent' => false,   // "urgent" is the red EMERGENCY style; an overdue PM is not an emergency
                'actor_name' => 'System',
                'title' => $headline,
                'message' => Str::limit($t->location.' — '.$intro, 200),
                'url' => $url,
            ]));
        } catch (Throwable $e) {
            report($e);
        }

        if (! TicketMailer::enabled()) {
            return;
        }

        foreach ($users->filter(fn (User $u) => filled($u->email)) as $user) {
            try {
                Mail::to($user)->queue(new TicketNotificationMail($event, [
                    'name' => $user->name,
                    'subject' => "[{$t->ticket_no}] {$headline}",
                    'headline' => $headline,
                    'intro' => $intro,
                    'ticket_no' => $t->ticket_no,
                    'status' => $t->status->label(),
                    'details' => array_filter([
                        'Location' => $t->location,
                        'Due' => $t->pm_due_date?->format('M j, Y'),
                    ], fn ($v) => filled($v)),
                    'note' => null,
                    'url' => $url,
                    'confidential' => false,
                ]));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private static function guard(\Closure $do): void
    {
        try {
            $do();
        } catch (Throwable $e) {
            report($e);   // one bad ticket must not stop the others
        }
    }
}