<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\RequestStatusLog;
use App\Models\TicketReply;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The ONE place that changes a ticket. Every method validates the move and writes the history line
 * in the same database transaction, so the status and its log can never disagree.
 *
 * It checks the STATE rules (allowed transitions, reasons, notes). Who is ALLOWED to call each method
 * (roles/policies) is decided by the controllers/policies of the later phases.
 */
class TicketWorkflow
{
    public const STALE_MESSAGE = 'This ticket was just updated by someone else. Reload the page to see its latest status, then try again.';

    /**
     * File a new ticket with any number of files and items. Everything happens in ONE transaction:
     * if a file or an item fails, no ticket is left behind and files already saved are removed again.
     * Retries if two people get the same ticket number at the same instant.
     *
     * @param  UploadedFile|array<int, UploadedFile>|null  $files  request attachments (kind "request")
     * @param  array<int, array{item_name: string, quantity?: int|string, unit?: ?string, specification?: ?string}>  $items
     */
    public static function open(User $requester, array $data, UploadedFile|array|null $files = null, array $items = []): MaintenanceRequest
    {
        $files = array_values(array_filter(Arr::wrap($files)));
        $items = self::cleanItems($items);

        if ($items !== [] && blank($data['request_type'] ?? null)) {
            throw new DomainException('Items can only be added when a request type (materials, equipment or supplies) is chosen.');
        }

        // Refuse a bad upload BEFORE anything is written.
        if ($files !== [] && ($error = AttachmentService::checkMany($files))) {
            throw new DomainException($error);
        }

        $attempts = 0;

        while (true) {
            $stored = [];   // [disk, path] of files saved in this attempt, removed again if the attempt fails

            try {
                $ticket = DB::transaction(function () use ($requester, $data, $files, $items, &$stored) {
                    $emergency = (bool) ($data['is_emergency'] ?? false);

                    $ticket = MaintenanceRequest::create([
                        'requester_id' => $requester->id,
                        'department_id' => $data['department_id'] ?? $requester->department_id,
                        'category_id' => $data['category_id'],
                        'request_type' => $data['request_type'] ?? null,
                        'maintenance_type' => $data['maintenance_type'] ?? 'repair',
                        'location' => $data['location'],
                        'description' => $data['description'],
                        'needed_by' => $data['needed_by'] ?? null,
                        'reason' => $data['reason'] ?? null,
                        'is_confidential' => (bool) ($data['is_confidential'] ?? false),
                        'is_emergency' => $emergency,
                        'emergency_reason' => $emergency ? ($data['emergency_reason'] ?? null) : null,
                        'emergency_flagged_by' => $emergency ? $requester->id : null,
                        'emergency_flagged_at' => $emergency ? now() : null,
                        'status' => TicketStatus::Submitted,
                    ]);

                    $ticket->logEvent(RequestStatusLog::ACTION_STATUS, $requester, 'Ticket submitted.', null, TicketStatus::Submitted);

                    if ($emergency) {
                        $ticket->logEvent(RequestStatusLog::ACTION_EMERGENCY, $requester, $ticket->emergency_reason,
                            meta: ['flagged' => true]);
                    }

                    // Someone without the flag_emergency capability asked for an emergency: the ticket stays
                    // routine, and the request is kept on the history for Maintenance to review.
                    if (! $emergency && filled($data['emergency_review_reason'] ?? null)) {
                        $ticket->logEvent(RequestStatusLog::ACTION_NOTE, $requester,
                            'Requester asked for emergency review: '.$data['emergency_review_reason'],
                            meta: ['emergency_review_requested' => true]);
                    }

                    foreach ($items as $item) {
                        $ticket->items()->create($item);
                    }

                    foreach ($files as $file) {
                        self::storeAttachment($ticket, $file, $requester, RequestAttachment::KIND_REQUEST, $stored);
                    }

                    return $ticket;
                });

                break;
            } catch (UniqueConstraintViolationException $e) {
                AttachmentService::discard($stored);

                if (++$attempts >= 5) {
                    throw $e;
                }
            } catch (Throwable $e) {
                AttachmentService::discard($stored);

                throw $e;
            }
        }

        $ticket->load(['attachments', 'items']);

        if ($files !== []) {
            self::auditUpload($ticket, $ticket->attachments, $requester, RequestAttachment::KIND_REQUEST);
        }

        TicketMailer::submitted($ticket, $requester);   // Phase 13: queued email to the maintenance staff (never throws)

        return $ticket;
    }

    /** Add ONE file to an existing ticket (a history line is written). See addAttachments(). */
    public static function addAttachment(
        MaintenanceRequest $ticket,
        UploadedFile $file,
        User $by,
        string $kind = RequestAttachment::KIND_REQUEST,
    ): RequestAttachment {
        return self::addAttachments($ticket, [$file], $by, $kind)->first();
    }

    /**
     * Add one upload (1..max_files files) of one `kind` to an existing ticket, with ONE history line.
     * Enforces the file rules (type by content, 5 MB, count, names). WHO may add files is TicketPolicy@addAttachment.
     * The closure rule for the signed_evidence kind is enforced in Phase 4B; here it is only uploadable.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, RequestAttachment>
     */
    public static function addAttachments(
        MaintenanceRequest $ticket,
        array $files,
        User $by,
        string $kind = RequestAttachment::KIND_REQUEST,
    ): Collection {
        if (! in_array($kind, RequestAttachment::KINDS, true)) {
            throw new DomainException('Unknown attachment type "'.$kind.'".');
        }

        $files = array_values(array_filter($files));
        if ($files === []) {
            throw new DomainException('Choose at least one file.');
        }
        if ($error = AttachmentService::checkMany($files)) {
            throw new DomainException($error);
        }

        $stored = [];

        try {
            $created = DB::transaction(function () use ($ticket, $files, $by, $kind, &$stored) {
                $created = new Collection;

                foreach ($files as $file) {
                    $created->push(self::storeAttachment($ticket, $file, $by, $kind, $stored));
                }

                $names = $created->pluck('original_name')->implode(', ');
                $count = $created->count();
                $ticket->logEvent(
                    RequestStatusLog::ACTION_ATTACHMENT,
                    $by,
                    sprintf('Added %s (%s): %s', $count === 1 ? '1 file' : "{$count} files", RequestAttachment::kindLabels()[$kind], $names),
                    meta: ['kind' => $kind, 'attachment_ids' => $created->pluck('id')->all()],
                );

                return $created;
            });

            self::auditUpload($ticket, $created, $by, $kind);   // once per upload, after the files are saved

            return $created;
        } catch (Throwable $e) {
            AttachmentService::discard($stored);

            throw $e;
        }
    }

    /** A plain status change (the transition map decides if it is allowed). */
    public static function transition(MaintenanceRequest $ticket, TicketStatus $to, ?User $by = null, ?string $note = null): MaintenanceRequest
    {
        return self::apply($ticket, $to, [], RequestStatusLog::ACTION_STATUS, $by, $note);
    }

    /** Maintenance sends a Submitted ticket to the authorizer's queue (Submitted -> Pending Authorization). */
    public static function sendForAuthorization(MaintenanceRequest $ticket, User $by, ?string $note = null): MaintenanceRequest
    {
        if ($ticket->status !== TicketStatus::Submitted) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\". Only a Submitted ticket can be sent for authorization.");
        }

        return self::apply($ticket, TicketStatus::PendingAuthorization, [], RequestStatusLog::ACTION_STATUS, $by, $note ?: 'Sent for authorization.');
    }

    /**
     * Record authorization and move to Approved. Only the roles in school.workflow.authorizers may do it
     * (CONFIRM WITH CLIENT). Optional signed files are saved as `signed_evidence` in the SAME transaction.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public static function authorize(MaintenanceRequest $ticket, User $by, ?string $remarks = null, array $files = []): MaintenanceRequest
    {
        self::assertAuthorizer($by, 'authorize');
        self::assertAuthorizableStatus($ticket, TicketStatus::Approved);

        $files = array_values(array_filter($files));
        if ($files !== [] && ($error = AttachmentService::checkMany($files))) {
            throw new DomainException($error);   // refused BEFORE anything is written
        }

        return DB::transaction(function () use ($ticket, $by, $remarks, $files) {
            self::apply($ticket, TicketStatus::Approved, [
                'president_id' => $by->id,
                'president_approved_at' => now(),
                'president_remarks' => $remarks,
            ], RequestStatusLog::ACTION_AUTHORIZATION, $by, $remarks ?: 'Authorized.');

            if ($files !== []) {
                self::addAttachments($ticket, $files, $by, RequestAttachment::KIND_SIGNED_EVIDENCE);
            }

            return $ticket->refresh();
        });
    }

    public static function disapprove(MaintenanceRequest $ticket, User $by, string $reason): MaintenanceRequest
    {
        self::assertAuthorizer($by, 'disapprove');

        if (blank($reason)) {
            throw new DomainException('Disapproving a ticket needs a reason.');
        }

        self::assertAuthorizableStatus($ticket, TicketStatus::Disapproved);

        return self::apply($ticket, TicketStatus::Disapproved, [], RequestStatusLog::ACTION_AUTHORIZATION, $by, $reason);
    }

    /** Phase 16: the statuses a ticket may be assigned in (also used by the Reports "Needs attention" list). @return list<TicketStatus> */
    public static function assignableStatuses(): array
    {
        return [TicketStatus::Approved, TicketStatus::Assigned, TicketStatus::InProgress, TicketStatus::Reopened];
    }

    /**
     * Assign (or reassign) to an active maintenance staff member.
     * A reassignment needs a note. An Approved ticket becomes Assigned; other statuses keep theirs.
     */
    public static function assign(MaintenanceRequest $ticket, User $assignee, ?User $by = null, ?string $note = null): MaintenanceRequest
    {
        if (! $assignee->is_active || ! $assignee->isMaintenanceTeam()) {
            throw new DomainException('A ticket can only be assigned to active maintenance staff.');
        }

        $status = $ticket->status;
        if (! in_array($status, self::assignableStatuses(), true)) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$status->label()}\". It must be Approved before it can be assigned.");
        }

        if ($ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $assignee->id) {
            return $ticket;   // already theirs, nothing to do
        }

        // WHO may do it (the status and the note rules are checked here too, so a direct call cannot skip them):
        // the Head assigns anyone; a staff-only member takes an unassigned ticket for themselves or transfers their
        // own. System callers (the PM scheduler) pass no $by and are not checked.
        if ($by !== null && $by->isStaffOnly()) {
            $claiming = $ticket->assigned_to === null && (int) $assignee->id === (int) $by->id;
            $transferring = $ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $by->id;

            if (! $claiming && ! $transferring) {
                throw new DomainException('Maintenance staff can take an unassigned ticket or transfer their own ticket. Only the Head of Maintenance can assign a ticket to someone else.');
            }
        }

        $previous = $ticket->assignee;
        $isReassignment = $previous !== null;

        if ($isReassignment && blank($note)) {
            throw new DomainException('A reassignment needs a note explaining why.');
        }

        $text = $isReassignment
            ? "Reassigned from {$previous->name} to {$assignee->name}. {$note}"
            : "Assigned to {$assignee->name}.".($note ? " {$note}" : '');

        return self::apply(
            $ticket,
            $status === TicketStatus::Approved ? TicketStatus::Assigned : null,
            ['assigned_to' => $assignee->id, 'assigned_at' => now()],
            $isReassignment ? RequestStatusLog::ACTION_REASSIGNMENT : RequestStatusLog::ACTION_ASSIGNMENT,
            $by,
            trim($text),
            ['from_user_id' => $previous?->id, 'to_user_id' => $assignee->id, 'reason' => $isReassignment ? trim((string) $note) : null],
        );
    }

    /** Board button [ACCEPT TASK]: take the ticket and start working on it. */
    public static function startWork(MaintenanceRequest $ticket, User $staff): MaintenanceRequest
    {
        if (! $staff->is_active || ! $staff->isMaintenanceTeam()) {
            throw new DomainException('Only maintenance staff can accept a task.');
        }

        if (! in_array($ticket->status, [TicketStatus::Approved, TicketStatus::Assigned, TicketStatus::Reopened], true)) {
            throw new DomainException(in_array($ticket->status, [TicketStatus::Submitted, TicketStatus::PendingAuthorization], true)
                ? "Ticket {$ticket->ticket_no} is still \"{$ticket->status->label()}\" — it needs authorization before work can start."
                : "Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and cannot be accepted.");
        }

        if (in_array($ticket->status, [TicketStatus::Assigned, TicketStatus::Reopened], true) && $ticket->assigned_to !== null && (int) $ticket->assigned_to !== (int) $staff->id) {
            throw new DomainException("This task is assigned to {$ticket->assignee?->name}.");
        }

        return DB::transaction(function () use ($ticket, $staff) {
            self::assign($ticket, $staff, $staff);   // Approved -> Assigned (no-op when already theirs; a Reopened ticket keeps its status)

            return self::transition($ticket, TicketStatus::InProgress, $staff, 'Work started.');
        });
    }

    /** Board button [MARK COMPLETED]. Problems/recommendations are optional and feed the reports. */
    public static function markWorkCompleted(
        MaintenanceRequest $ticket,
        User $staff,
        ?string $problems = null,
        ?string $recommendations = null,
    ): MaintenanceRequest {
        if (! $staff->is_active || ! $staff->isMaintenanceTeam()) {
            throw new DomainException('Only maintenance staff can complete a task.');
        }

        return self::apply($ticket, TicketStatus::WorkCompleted, array_filter([
            'completed_at' => now(),
            'problems_encountered' => $problems,
            'recommendations' => $recommendations,
        ], fn ($v) => $v !== null), RequestStatusLog::ACTION_STATUS, $staff, 'Work completed.');
    }

    /** Formal closure. The model refuses it unless authorization is recorded. */
    public static function close(MaintenanceRequest $ticket, User $by, ?string $note = null): MaintenanceRequest
    {
        return self::apply($ticket, TicketStatus::Closed, ['closed_at' => now()], RequestStatusLog::ACTION_STATUS, $by, $note ?: 'Ticket closed.');
    }

    // ---- Phase 8: the requester's own actions (cancel, confirm resolved, reopen) --------------------
    // The policy (cancel_own / reopen_own) is the first gate; these methods repeat the "own ticket" check and
    // add the STATE rules, so calling them directly can never skip a rule.

    /**
     * Why $by cannot cancel $ticket right now, or null when they can. CONFIRM WITH CLIENT: "before Assigned".
     * The status table says which statuses may move to Cancelled; a ticket that already has an assignee is
     * refused too (e.g. Waiting for User / Escalated on a ticket that was already assigned).
     */
    public static function cancelBlocker(MaintenanceRequest $ticket, User $by): ?string
    {
        if ((int) $ticket->requester_id !== (int) $by->id) {
            return 'Only the person who filed the ticket can cancel it.';
        }

        if (! $ticket->status->canTransitionTo(TicketStatus::Cancelled) || $ticket->assigned_to !== null) {
            return "Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and can no longer be cancelled. A ticket can only be cancelled before it is assigned.";
        }

        return null;
    }

    /** The requester cancels their OWN ticket, before it is Assigned. A reason is required. */
    public static function cancel(MaintenanceRequest $ticket, User $by, string $reason): MaintenanceRequest
    {
        if (blank($reason)) {
            throw new DomainException('Cancelling a ticket needs a reason.');
        }

        if ($error = self::cancelBlocker($ticket, $by)) {
            throw new DomainException($error);
        }

        return self::apply($ticket, TicketStatus::Cancelled, [], RequestStatusLog::ACTION_STATUS, $by, 'Cancelled by the requester: '.trim($reason));
    }

    /** The last moment a Work Completed / Closed ticket may be reopened, or null when it is not in one of those statuses. */
    public static function reopenDeadline(MaintenanceRequest $ticket): ?Carbon
    {
        $anchor = match ($ticket->status) {
            TicketStatus::WorkCompleted => $ticket->completed_at,
            TicketStatus::Closed => $ticket->closed_at ?? $ticket->completed_at,
            default => null,
        };

        if (! in_array($ticket->status, [TicketStatus::WorkCompleted, TicketStatus::Closed], true)) {
            return null;
        }

        $days = Setting::int('reopen_window_days');

        return ($anchor ?? $ticket->updated_at)->copy()->addDays(max(0, $days));
    }

    /**
     * Why $by cannot reopen $ticket right now, or null when they can.
     * Only Work Completed / Closed, only the requester, and only inside settings.reopen_window_days
     * (counted from completed_at, or closed_at for a Closed ticket). A window of 0 days turns reopening off.
     */
    public static function reopenBlocker(MaintenanceRequest $ticket, User $by): ?string
    {
        if ((int) $ticket->requester_id !== (int) $by->id) {
            return 'Only the person who filed the ticket can reopen it.';
        }

        if (! in_array($ticket->status, [TicketStatus::WorkCompleted, TicketStatus::Closed], true)) {
            return "Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\". Only a Work Completed or Closed ticket can be reopened.";
        }

        $days = Setting::int('reopen_window_days');

        if ($days <= 0) {
            return 'Reopening tickets is turned off. File a new ticket instead.';
        }

        if (now()->greaterThan(self::reopenDeadline($ticket))) {
            return "The {$days}-day window to reopen ticket {$ticket->ticket_no} has ended. File a new ticket instead.";
        }

        return null;
    }

    /**
     * The requester reopens their OWN ticket inside the window. A reason is required.
     * The ticket goes to Reopened and its completed/closed dates are cleared (they are set again when the work is
     * redone); the history line keeps the original status in meta.reopened_from.
     */
    public static function reopen(MaintenanceRequest $ticket, User $by, string $reason): MaintenanceRequest
    {
        if (blank($reason)) {
            throw new DomainException('Reopening a ticket needs a reason.');
        }

        if ($error = self::reopenBlocker($ticket, $by)) {
            throw new DomainException($error);
        }

        return self::apply(
            $ticket,
            TicketStatus::Reopened,
            ['completed_at' => null, 'closed_at' => null],
            RequestStatusLog::ACTION_STATUS,
            $by,
            'Reopened by the requester: '.trim($reason),
            ['reopened_from' => $ticket->status->value],
        );
    }

    /** [Confirm resolved]: the requester agrees the work is done, so a Work Completed ticket is closed at once. */
    public static function confirmResolved(MaintenanceRequest $ticket, User $by): MaintenanceRequest
    {
        if ((int) $ticket->requester_id !== (int) $by->id) {
            throw new DomainException('Only the person who filed the ticket can confirm it is resolved.');
        }

        if ($ticket->status !== TicketStatus::WorkCompleted) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\". Only a Work Completed ticket can be confirmed as resolved.");
        }

        return self::close($ticket, $by, 'Confirmed resolved by the requester.');
    }

    // ---- Phase 9: ask the requester for information, and auto-close when nobody answers -------------
    // Waiting for User PAUSES the SLA clock: it is never Overdue while waiting, and when the ticket leaves
    // Waiting for User its due_at moves later by the time it waited (see apply()). The start of the wait is the
    // history line that entered Waiting for User, so no new column is needed.

    /** The history line that put the ticket in Waiting for User (the latest one), or null when it is not waiting. */
    public static function waitingLog(MaintenanceRequest $ticket): ?RequestStatusLog
    {
        if ($ticket->status !== TicketStatus::WaitingForUser) {
            return null;
        }

        return $ticket->statusLogs()->reorder('id', 'desc')
            ->where('to_status', TicketStatus::WaitingForUser->value)->first();
    }

    /** When the current wait started, or null when the ticket is not waiting. */
    public static function waitingSince(MaintenanceRequest $ticket): ?Carbon
    {
        return self::waitingLog($ticket)?->created_at;
    }

    /**
     * Staff (request_info) ask the requester a question: the ticket goes to Waiting for User and the SLA clock pauses.
     * The status it came from is kept in the history line, so the reply can send it back there.
     */
    public static function requestInfo(MaintenanceRequest $ticket, User $by, string $question): MaintenanceRequest
    {
        if (! $by->is_active || ! $by->can_do('request_info')) {
            throw new DomainException('Only maintenance staff can ask the requester for information.');
        }

        if (blank($question)) {
            throw new DomainException('Write what you need to know from the requester.');
        }

        if ($ticket->status === TicketStatus::WaitingForUser) {
            throw new DomainException("Ticket {$ticket->ticket_no} is already waiting for the requester.");
        }

        if (! $ticket->status->canTransitionTo(TicketStatus::WaitingForUser)) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\". Information can no longer be requested.");
        }

        return self::apply(
            $ticket,
            TicketStatus::WaitingForUser,
            [],
            RequestStatusLog::ACTION_STATUS,
            $by,
            'Information requested: '.trim($question),
            ['info_requested' => true, 'waiting_from' => $ticket->status->value],
        );
    }

    /**
     * The requester answers. The ticket goes back to the status it was in when the question was asked
     * (In Progress when staff asked during the work), and the SLA clock runs again with the waited time added.
     */
    public static function respondToInfo(MaintenanceRequest $ticket, User $by, string $reply): MaintenanceRequest
    {
        if ((int) $ticket->requester_id !== (int) $by->id) {
            throw new DomainException('Only the person who filed the ticket can answer.');
        }

        if ($ticket->status !== TicketStatus::WaitingForUser) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\". It is not waiting for an answer.");
        }

        if (blank($reply)) {
            throw new DomainException('Write your answer first.');
        }

        $back = TicketStatus::tryFrom((string) self::waitingLog($ticket)?->from_status)
            ?? ($ticket->assigned_to !== null ? TicketStatus::InProgress : TicketStatus::Submitted);

        return self::apply($ticket, $back, [], RequestStatusLog::ACTION_STATUS, $by,
            'Requester replied: '.trim($reply), ['info_reply' => true]);
    }

    /**
     * Close a ticket that has waited for the requester for settings.autoclose_waiting_days (default 7).
     * It becomes CANCELLED (the status table has no Waiting for User -> Closed, and Closed needs authorization).
     * Called by the tickets:autoclose command; it refuses a ticket that is not waiting or not waiting long enough.
     */
    public static function autoCloseWaiting(MaintenanceRequest $ticket, ?int $days = null): MaintenanceRequest
    {
        $days ??= Setting::int('autoclose_waiting_days');

        if ($days <= 0) {
            throw new DomainException('Auto-close is turned off.');
        }

        $since = self::waitingSince($ticket);

        if ($since === null) {
            throw new DomainException("Ticket {$ticket->ticket_no} is not waiting for the requester.");
        }

        if (now()->lessThan($since->copy()->addDays($days))) {
            throw new DomainException("Ticket {$ticket->ticket_no} has not waited {$days} days yet.");
        }

        return self::apply(
            $ticket,
            TicketStatus::Cancelled,
            [],
            RequestStatusLog::ACTION_STATUS,
            null,
            "Auto-closed: no reply from the requester for {$days} days.",
            ['auto_closed' => true, 'waiting_since' => $since->toIso8601String()],
        );
    }

    // ---- Phase 12: escalation ---------------------------------------------------------------------
    // A ticket is escalated by hand (staff with the `escalate` capability, a reason is required) or by the
    // tickets:escalate command when it is past due_at. Both end in the same status, Escalated, and alert the
    // President (school.workflow.authorizers) and the Maintenance Admin. A ticket that is Waiting for User is
    // never escalated: its SLA clock is paused. The status it came from is kept in the history line
    // (meta.escalated_from).

    /**
     * The statuses the tickets:escalate command may move: open statuses the status table lets go to Escalated,
     * except Waiting for User (paused) and Escalated itself. Closed, Work Completed, Disapproved and Cancelled never qualify.
     *
     * @return list<TicketStatus>
     */
    public static function escalatableStatuses(): array
    {
        return array_values(array_filter(
            TicketStatus::pendingCases(),
            fn (TicketStatus $s) => $s !== TicketStatus::WaitingForUser && $s->canTransitionTo(TicketStatus::Escalated),
        ));
    }

    /** @return list<string> */
    public static function escalatableValues(): array
    {
        return array_map(fn (TicketStatus $s) => $s->value, self::escalatableStatuses());
    }

    /** Why $ticket cannot be escalated right now (by anyone, by hand or by the command), or null when it can. */
    public static function escalateBlocker(MaintenanceRequest $ticket): ?string
    {
        if ($ticket->status === TicketStatus::Escalated) {
            return "Ticket {$ticket->ticket_no} is already escalated.";
        }

        if ($ticket->status === TicketStatus::WaitingForUser) {
            return "Ticket {$ticket->ticket_no} is waiting for the requester. Its target time is paused, so it cannot be escalated.";
        }

        if (! in_array($ticket->status, self::escalatableStatuses(), true)) {
            return "Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and can no longer be escalated.";
        }

        return null;
    }

    /** Staff (escalate capability) hand the ticket up to the authorizer. A reason is required. */
    public static function escalate(MaintenanceRequest $ticket, User $by, string $reason): MaintenanceRequest
    {
        if (! $by->is_active || ! $by->can_do('escalate')) {
            throw new DomainException('Only maintenance staff can escalate a ticket.');
        }

        if (blank($reason)) {
            throw new DomainException('Escalating a ticket needs a reason.');
        }

        if ($error = self::escalateBlocker($ticket)) {
            throw new DomainException($error);
        }

        return self::apply(
            $ticket,
            TicketStatus::Escalated,
            [],
            RequestStatusLog::ACTION_STATUS,
            $by,
            'Escalated to the authorizer: '.trim($reason),
            ['escalated' => true, 'escalated_from' => $ticket->status->value],
        );
    }

    /**
     * Called by tickets:escalate. Moves a ticket that is past its target date to Escalated (no user: the history
     * line says it was automatic). It refuses a ticket that is not yet due, is paused, finished or already escalated,
     * so calling it directly can never skip a rule.
     */
    public static function autoEscalate(MaintenanceRequest $ticket): MaintenanceRequest
    {
        if ($ticket->due_at === null || $ticket->due_at->greaterThanOrEqualTo(now())) {
            throw new DomainException("Ticket {$ticket->ticket_no} is not past its target date yet.");
        }

        if ($error = self::escalateBlocker($ticket)) {
            throw new DomainException($error);
        }

        $due = $ticket->due_at->copy()->timezone(config('app.timezone'))->format('M j, Y g:i A');

        return self::apply(
            $ticket,
            TicketStatus::Escalated,
            [],
            RequestStatusLog::ACTION_STATUS,
            null,
            "Auto-escalated: the ticket passed its target date ({$due}).",
            ['escalated' => true, 'auto_escalated' => true, 'escalated_from' => $ticket->status->value, 'due_at' => $ticket->due_at->toIso8601String()],
        );
    }

    /** Raise an emergency flag. The reason is required. Who may flag is enforced in Phase 2. */
    public static function flagEmergency(MaintenanceRequest $ticket, User $by, string $reason): MaintenanceRequest
    {
        if (blank($reason)) {
            throw new DomainException('An emergency flag needs a reason.');
        }
        if ($ticket->status->isTerminal()) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and can no longer be flagged.");
        }
        if ($ticket->is_emergency) {
            throw new DomainException("Ticket {$ticket->ticket_no} is already flagged as an emergency.");
        }

        return self::apply($ticket, null, [
            'is_emergency' => true,
            'emergency_reason' => $reason,
            'emergency_flagged_by' => $by->id,
            'emergency_flagged_at' => now(),
        ], RequestStatusLog::ACTION_EMERGENCY, $by, $reason, ['flagged' => true]);
    }

    /** Remove the flag. submitted_at is never touched, so the ticket returns to its ORIGINAL routine position. */
    public static function clearEmergency(MaintenanceRequest $ticket, User $by, ?string $note = null): MaintenanceRequest
    {
        if ($ticket->status->isTerminal()) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and can no longer be changed.");
        }
        if (! $ticket->is_emergency) {
            throw new DomainException("Ticket {$ticket->ticket_no} is not flagged as an emergency.");
        }

        return self::apply($ticket, null, [
            'is_emergency' => false,
            'emergency_reason' => null,
            'emergency_flagged_by' => null,
            'emergency_flagged_at' => null,
        ], RequestStatusLog::ACTION_EMERGENCY, $by, $note ?: 'Emergency flag removed.', ['flagged' => false]);
    }

    /**
     * Phase 10: one message in the conversation on a ticket, with its files (0..max_files), saved together:
     * if a file is refused or saving fails, no reply is left behind and files already saved are removed again.
     * A reply is plain text (shown escaped) and/or files. It does NOT change the status; answering a question
     * from staff (Waiting for User) is still respondToInfo(). WHO may reply is TicketPolicy@reply.
     *
     * Phase 11: $internal makes it an internal note: staff only (TicketPolicy@addInternalNote), text only (a file
     * would have to be hidden from the requester too, so internal notes carry none).
     *
     * @param  array<int, UploadedFile>  $files
     */
    public static function addReply(MaintenanceRequest $ticket, User $by, ?string $body, array $files = [], bool $internal = false): TicketReply
    {
        $body = trim((string) $body);
        $files = array_values(array_filter($files));

        if ($body === '' && $files === []) {
            throw new DomainException('Write a message or attach a file.');
        }
        if (mb_strlen($body) > TicketReply::BODY_MAX) {
            throw new DomainException('A reply can be at most '.TicketReply::BODY_MAX.' characters.');
        }
        if ($internal && $files !== []) {
            throw new DomainException('An internal note cannot carry files. Send the file in a normal message, or leave it out.');
        }
        if ($internal && ! Gate::forUser($by)->allows('addInternalNote', $ticket)) {
            throw new DomainException('Only staff can write internal notes.');
        }
        if ($ticket->status->isTerminal()) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and the conversation is closed.");
        }
        if ($files !== [] && ($error = AttachmentService::checkMany($files))) {
            throw new DomainException($error);
        }

        $stored = [];

        try {
            $reply = DB::transaction(function () use ($ticket, $by, $body, $files, $internal, &$stored) {
                $reply = TicketReply::create([
                    'request_id' => $ticket->getKey(),
                    'user_id' => $by->id,
                    'body' => $body === '' ? null : $body,
                    'is_internal' => $internal,
                ]);

                foreach ($files as $file) {
                    $meta = AttachmentService::store($file);
                    $stored[] = [$meta['disk'], $meta['path']];

                    $reply->attachments()->create($meta + [
                        'request_id' => $ticket->getKey(),
                        'uploaded_by' => $by->id,
                        'kind' => RequestAttachment::KIND_REPLY,
                    ]);
                }

                return $reply;
            });
        } catch (Throwable $e) {
            AttachmentService::discard($stored);

            throw $e;
        }

        $reply->load('attachments');

        if ($reply->attachments->isNotEmpty()) {
            self::auditUpload($ticket, $reply->attachments, $by, RequestAttachment::KIND_REPLY);   // once per reply, after saving
        }

        // Phase 13: queued email for a PUBLIC message. An internal note is never emailed (TicketMailer refuses it).
        TicketMailer::replied($ticket, $by, $internal);

        return $reply;
    }

    /** A free-text action note on the history. */
    public static function addNote(MaintenanceRequest $ticket, User $by, string $note): RequestStatusLog
    {
        if (blank($note)) {
            throw new DomainException('A note cannot be empty.');
        }
        if ($ticket->status->isTerminal()) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and can no longer be changed.");
        }

        return $ticket->logEvent(RequestStatusLog::ACTION_NOTE, $by, $note);
    }

    // ---------------------------------------------------------------------------------------------

    /** Save one file (private disk, random name) and its row. Remembers the saved path in $stored. */
    private static function storeAttachment(MaintenanceRequest $ticket, UploadedFile $file, User $by, string $kind, array &$stored): RequestAttachment
    {
        $meta = AttachmentService::store($file);
        $stored[] = [$meta['disk'], $meta['path']];

        return $ticket->attachments()->create($meta + ['uploaded_by' => $by->id, 'kind' => $kind]);
    }

    /** Drop empty rows and tidy the rest. A row needs an item name; quantity is at least 1. */
    private static function cleanItems(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            if (! is_array($item) || blank($item['item_name'] ?? null)) {
                continue;
            }

            $clean[] = [
                'item_name' => trim((string) $item['item_name']),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit' => filled($item['unit'] ?? null) ? trim((string) $item['unit']) : null,
                'specification' => filled($item['specification'] ?? null) ? trim((string) $item['specification']) : null,
            ];
        }

        return $clean;
    }

    /** The signatory check shared by authorize() and disapprove(). Roles: school.workflow.authorizers. */
    private static function assertAuthorizer(User $by, string $verb): void
    {
        if (! $by->canAuthorize()) {
            throw new DomainException('Only the '.self::authorizerLabel()." can {$verb} a ticket.");
        }
    }

    /** "School President" (or "A or B") for the error messages, taken from the configured authorizer roles. */
    private static function authorizerLabel(): string
    {
        $names = Role::query()
            ->whereIn('slug', (array) config('school.workflow.authorizers', []))
            ->orderBy('name')->pluck('name')->all();

        return $names === [] ? 'authorized signatory' : implode(' or ', $names);
    }

    /**
     * The ticket must be in a status the authorizer can act on. With school.workflow.authorize_from_submitted
     * off it must be sent for authorization first. A ticket that was already decided (or is final) is refused
     * with the usual "cannot move from ... to ..." message instead of being decided twice.
     */
    private static function assertAuthorizableStatus(MaintenanceRequest $ticket, TicketStatus $to): void
    {
        if ($ticket->status === TicketStatus::Submitted && ! config('school.workflow.authorize_from_submitted', true)) {
            throw new DomainException("Ticket {$ticket->ticket_no} is still \"Submitted\". It must be sent for authorization first.");
        }

        // Phase 7: Waiting for User and Escalated can also move to Approved / Disapproved, but approving them is
        // not an action yet (Phase 9 decides it), so only the authorizable statuses are accepted here.
        if (! in_array($ticket->status->value, MaintenanceRequest::authorizableStatusValues(), true)) {
            throw new DomainException(MaintenanceRequest::invalidTransitionMessage($ticket->ticket_no, $ticket->status, $to));
        }

        if (! $ticket->status->canTransitionTo($to)) {
            throw new DomainException(MaintenanceRequest::invalidTransitionMessage($ticket->ticket_no, $ticket->status, $to));
        }
    }

    /**
     * Validate the move, update the ticket and write ONE history line, atomically.
     *
     * The ticket row is re-read with lockForUpdate() inside the transaction, so two people cannot change the
     * same ticket at the same instant. If it changed since the caller loaded it, the second person gets a
     * clear "just updated" message instead of silently overwriting (or repeating) the first person's action.
     */
    private static function apply(
        MaintenanceRequest $ticket,
        ?TicketStatus $to,
        array $attributes,
        string $action,
        ?User $by,
        ?string $note,
        ?array $meta = null,
    ): MaintenanceRequest {
        $from = $ticket->status;

        if ($to !== null && $to !== $from && ! $from->canTransitionTo($to)) {
            throw new DomainException(MaintenanceRequest::invalidTransitionMessage($ticket->ticket_no, $from, $to));
        }

        $log = DB::transaction(function () use ($ticket, $to, $from, $attributes, $action, $by, $note, $meta) {
            $current = MaintenanceRequest::query()->lockForUpdate()->find($ticket->getKey());

            if ($current === null
                || $current->status !== $from
                || (int) $current->assigned_to !== (int) $ticket->assigned_to
                || (bool) $current->is_emergency !== (bool) $ticket->is_emergency) {
                throw new DomainException(self::STALE_MESSAGE);
            }

            // Phase 9: leaving Waiting for User un-pauses the SLA clock: due_at moves later by the time it waited.
            if ($from === TicketStatus::WaitingForUser && $to !== null && $to !== $from && $to->isPending() && $ticket->due_at !== null) {
                $since = self::waitingSince($ticket);

                if ($since !== null) {
                    $attributes['due_at'] = $ticket->due_at->copy()->addSeconds(abs((int) $since->diffInSeconds(now())));
                }
            }

            if ($to !== null) {
                $attributes['status'] = $to;
            }

            $ticket->update($attributes);   // the model's own guards run here too

            return $ticket->logEvent($action, $by, $note, $to !== null ? $from : null, $to, $meta);
        });

        self::auditChange($ticket, $from, $to, $action, $by, $meta, $log);

        $ticket->refresh();

        // Phase 13: queued emails for the status change / assignment (never throws, so it can never block the action)
        TicketMailer::changed($ticket, $from, $to, $action, $by, $note, $meta);

        return $ticket;
    }

    // ---- audit trail (Merge Phase M3) ------------------------------------------------------------
    // Short rows only. The note/reason stays in request_status_logs; meta.status_log_id points at it.
    // AuditLogger never throws, and these helpers swallow anything else, so logging can never block the action.

    /** One row for a status change and/or one for an assignment, each pointing at the history line just written. */
    private static function auditChange(
        MaintenanceRequest $ticket,
        TicketStatus $from,
        ?TicketStatus $to,
        string $action,
        ?User $by,
        ?array $meta,
        RequestStatusLog $log,
    ): void {
        try {
            $no = $ticket->ticket_no;

            if ($to !== null && $to !== $from) {
                AuditLogger::log(AuditLogger::TICKET_STATUS, "{$no}: {$from->label()} -> {$to->label()}", $ticket, $by, [
                    'status_log_id' => $log->getKey(),
                ]);
            }

            if (in_array($action, [RequestStatusLog::ACTION_ASSIGNMENT, RequestStatusLog::ACTION_REASSIGNMENT], true)) {
                $isReassignment = $action === RequestStatusLog::ACTION_REASSIGNMENT;
                $to_name = User::query()->whereKey($meta['to_user_id'] ?? null)->value('name') ?? 'unknown';
                $from_name = User::query()->whereKey($meta['from_user_id'] ?? null)->value('name') ?? 'unknown';

                AuditLogger::log(
                    $isReassignment ? AuditLogger::TICKET_REASSIGNED : AuditLogger::TICKET_ASSIGNED,
                    $isReassignment ? "{$no}: Reassigned from {$from_name} to {$to_name}" : "{$no}: Assigned to {$to_name}",
                    $ticket,
                    $by,
                    ['status_log_id' => $log->getKey(), 'from_user_id' => $meta['from_user_id'] ?? null, 'to_user_id' => $meta['to_user_id'] ?? null],
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** ONE row per upload (not per file). File names only; the status log line has the rest. */
    private static function auditUpload(MaintenanceRequest $ticket, iterable $files, User $by, string $kind): void
    {
        try {
            $files = collect($files);

            if ($files->isEmpty()) {
                return;
            }

            $count = $files->count();

            AuditLogger::log(
                AuditLogger::ATTACHMENT_UPLOADED,
                sprintf('%s: Uploaded %s: %s', $ticket->ticket_no, $count === 1 ? '1 file' : "{$count} files", $files->pluck('original_name')->implode(', ')),
                $ticket,
                $by,
                ['kind' => $kind, 'attachment_ids' => $files->pluck('id')->all()],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
