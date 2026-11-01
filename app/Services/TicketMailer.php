<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Mail\TicketNotificationMail;
use App\Models\MaintenanceRequest;
use App\Models\RequestStatusLog;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 13: decides WHO gets an email for a ticket event and WHAT it says, then queues one
 * TicketNotificationMail per person. TicketWorkflow calls it after a ticket is filed, changed or answered.
 *
 * Rules (see docs/OPEN_QUESTIONS.md rows 42-46):
 *  - the person who did the action is never emailed; inactive users and users who may not see the ticket are skipped;
 *  - an internal note is never emailed; a normal message is emailed WITHOUT its text;
 *  - a confidential ticket's email has no location, description or note, only the ticket number, status and a link;
 *  - it never throws: a failed email can never block or undo the ticket action (the error is reported to the log).
 *
 * The in-app alerts (FormNotifier) are separate and unchanged.
 */
class TicketMailer
{
    /** Events whose history note is useful to the reader (a reason, a question, an answer). */
    private const NOTE_EVENTS = ['disapproved', 'info_requested', 'info_answered', 'reopened', 'cancelled', 'escalated'];

    public static function enabled(): bool
    {
        return (bool) config('school.ticket_emails', true);
    }

    /** A new ticket was filed: the maintenance staff are emailed. */
    public static function submitted(MaintenanceRequest $ticket, User $by): void
    {
        self::guard(fn () => self::deliver(
            $ticket, 'submitted', 'New ticket submitted', self::name($by).' filed a new ticket.',
            self::admins(), $by,
        ));
    }

    /**
     * A status change or an assignment, called by TicketWorkflow::apply() once it is saved.
     * $ticket is the refreshed ticket (its new status / assignee).
     */
    public static function changed(
        MaintenanceRequest $ticket,
        TicketStatus $from,
        ?TicketStatus $to,
        string $action,
        ?User $by,
        ?string $note = null,
        ?array $meta = null,
    ): void {
        self::guard(function () use ($ticket, $from, $to, $action, $by, $note) {
            $who = self::name($by);

            // Assigned / reassigned: the new assignee and the requester.
            if (in_array($action, [RequestStatusLog::ACTION_ASSIGNMENT, RequestStatusLog::ACTION_REASSIGNMENT], true)) {
                $assignee = $ticket->assignee;

                // Accepting a task yourself: no "assigned" email, the "work started" email for the requester follows.
                if ($assignee === null || ($by !== null && (int) $assignee->id === (int) $by->id)) {
                    return;
                }

                $verb = $action === RequestStatusLog::ACTION_REASSIGNMENT ? 'reassigned' : 'assigned';

                self::deliver($ticket, 'assigned', 'Ticket '.$verb, "{$who} {$verb} the ticket to {$assignee->name}.",
                    self::people($ticket->requester, $assignee), $by);

                return;
            }

            if ($to === null || $to === $from) {
                return;   // a flag, a note: not a status change
            }

            // The requester answered a question: the staff can carry on.
            if ($from === TicketStatus::WaitingForUser && ! in_array($to, [TicketStatus::Escalated, TicketStatus::Cancelled], true)) {
                self::deliver($ticket, 'info_answered', 'The requester answered', "{$who} answered the question. The work can continue.",
                    self::staff($ticket), $by, $note);

                return;
            }

            [$event, $headline, $intro, $recipients] = match ($to) {
                TicketStatus::PendingAuthorization => [
                    'sent_for_authorization', 'Ticket waiting for authorization',
                    "{$who} sent the ticket for authorization.", self::authorizers(),
                ],
                TicketStatus::Approved => [
                    'approved', 'Ticket approved', "{$who} approved the ticket.",
                    self::people($ticket->requester)->merge(self::admins()),
                ],
                TicketStatus::Disapproved => [
                    'disapproved', 'Ticket disapproved', "{$who} disapproved the ticket.",
                    self::people($ticket->requester)->merge(self::admins()),
                ],
                TicketStatus::WaitingForUser => [
                    'info_requested', 'Information needed',
                    "{$who} needs more information about your ticket. Please open it and answer.", self::people($ticket->requester),
                ],
                TicketStatus::InProgress => [
                    'in_progress', 'Work started', "{$who} started working on the ticket.", self::people($ticket->requester),
                ],
                TicketStatus::WorkCompleted => [
                    'work_completed', 'Work completed',
                    "{$who} finished the work. Please open the ticket and confirm that the problem is fixed.", self::people($ticket->requester),
                ],
                TicketStatus::Closed => [
                    'closed', 'Ticket closed', "{$who} closed the ticket.",
                    self::people($ticket->requester)->merge(self::staff($ticket)),
                ],
                TicketStatus::Reopened => [
                    'reopened', 'Ticket reopened', "{$who} reopened the ticket.", self::staff($ticket),
                ],
                TicketStatus::Cancelled => [
                    'cancelled', 'Ticket cancelled',
                    $by === null ? 'The ticket was closed automatically: the requester did not answer in time.' : "{$who} cancelled the ticket.",
                    self::people($ticket->requester)->merge(self::staff($ticket)),
                ],
                TicketStatus::Escalated => [
                    'escalated', 'Ticket escalated',
                    $by === null ? 'The system escalated the ticket because it passed its target date.' : "{$who} escalated the ticket to the authorizer.",
                    self::authorizers()->merge(self::admins()),
                ],
                default => [
                    'status_changed', 'Ticket status changed', "{$who} changed the status to {$to->label()}.", self::people($ticket->requester),
                ],
            };

            self::deliver($ticket, $event, $headline, $intro, $recipients, $by, $note);
        });
    }

    /**
     * A message in the conversation. An internal note is NEVER emailed (Phase 11). A normal message is emailed
     * to the other side without its text: the requester's message goes to the staff, anyone else's to the requester + assignee.
     */
    public static function replied(MaintenanceRequest $ticket, User $by, bool $internal = false): void
    {
        if ($internal) {
            return;
        }

        self::guard(function () use ($ticket, $by) {
            $recipients = (int) $ticket->requester_id === (int) $by->id
                ? self::staff($ticket)
                : self::people($ticket->requester, $ticket->assignee);

            self::deliver($ticket, 'reply', 'New message on a ticket',
                self::name($by).' sent a message on the ticket. Open it to read and reply.', $recipients, $by);
        });
    }

    // ---------------------------------------------------------------------------------------------

    /** Queue one email per recipient. Never emails $by, an inactive user, or someone who may not see the ticket. */
    private static function deliver(
        MaintenanceRequest $ticket,
        string $event,
        string $headline,
        string $intro,
        Collection $recipients,
        ?User $by,
        ?string $note = null,
    ): void {
        $users = $recipients->filter()->unique('id')
            ->reject(fn (User $u) => $by !== null && (int) $u->id === (int) $by->id)
            ->filter(fn (User $u) => $u->is_active && filled($u->email) && $ticket->isVisibleTo($u));

        foreach ($users as $user) {
            try {
                Mail::to($user)->queue(new TicketNotificationMail($event, self::payload($ticket, $event, $headline, $intro, $user, $note)));
            } catch (Throwable $e) {
                report($e);   // one bad address must not stop the others
            }
        }
    }

    /** @return array<string, mixed> */
    private static function payload(MaintenanceRequest $ticket, string $event, string $headline, string $intro, User $to, ?string $note): array
    {
        $confidential = (bool) $ticket->is_confidential;

        return [
            'name' => $to->name,
            'subject' => "[{$ticket->ticket_no}] {$headline}",
            'headline' => $headline,
            'intro' => $intro,
            'ticket_no' => $ticket->ticket_no,
            'status' => $ticket->status->label(),
            // a confidential ticket's text never leaves the system by email
            'details' => $confidential ? [] : array_filter([
                'Location' => $ticket->location,
                'Request' => Str::limit((string) $ticket->description, 160),
            ], fn ($v) => filled($v)),
            'note' => ($confidential || blank($note) || ! in_array($event, self::NOTE_EVENTS, true))
                ? null
                : Str::limit(trim((string) $note), 500),
            'url' => route('tickets.show', $ticket),
            'confidential' => $confidential,
        ];
    }

    private static function guard(Closure $do): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $do();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function name(?User $by): string
    {
        return $by?->name ?? 'The system';
    }

    /** @return Collection<int, User> */
    private static function people(?User ...$users): Collection
    {
        return collect($users)->filter()->values();
    }

    /** The maintenance staff plus the person the ticket is assigned to. */
    private static function staff(MaintenanceRequest $ticket): Collection
    {
        return self::admins()->push($ticket->assignee);
    }

    private static function admins(): Collection
    {
        return self::withRole(Role::MAINTENANCE_ADMIN);
    }

    private static function authorizers(): Collection
    {
        $users = collect();

        foreach ((array) config('school.workflow.authorizers', []) as $slug) {
            $users = $users->merge(self::withRole((string) $slug));
        }

        return $users;
    }

    private static function withRole(string $slug): Collection
    {
        return User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', $slug))
            ->get();
    }
}
