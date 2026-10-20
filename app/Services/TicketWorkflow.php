<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\RequestStatusLog;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

        return $ticket->load(['attachments', 'items']);
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
     * Phase 4 adds the closure rules for the signed_evidence kind; here it is only uploadable.
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
            return DB::transaction(function () use ($ticket, $files, $by, $kind, &$stored) {
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

    /** Record authorization and move to Approved. Only the School President may authorize (confirm with client). */
    public static function authorize(MaintenanceRequest $ticket, User $president, ?string $remarks = null): MaintenanceRequest
    {
        if (! $president->is_active || ! $president->isPresident()) {
            throw new DomainException('Only the School President can authorize a ticket.');
        }

        return self::apply($ticket, TicketStatus::Approved, [
            'president_id' => $president->id,
            'president_approved_at' => now(),
            'president_remarks' => $remarks,
        ], RequestStatusLog::ACTION_AUTHORIZATION, $president, $remarks ?: 'Authorized.');
    }

    public static function disapprove(MaintenanceRequest $ticket, User $by, string $reason): MaintenanceRequest
    {
        if (blank($reason)) {
            throw new DomainException('Disapproving a ticket needs a reason.');
        }

        return self::apply($ticket, TicketStatus::Disapproved, [], RequestStatusLog::ACTION_AUTHORIZATION, $by, $reason);
    }

    /**
     * Assign (or reassign) to an active maintenance staff member.
     * A reassignment needs a note. An Approved ticket becomes Assigned; other statuses keep theirs.
     */
    public static function assign(MaintenanceRequest $ticket, User $assignee, ?User $by = null, ?string $note = null): MaintenanceRequest
    {
        if (! $assignee->is_active || ! $assignee->hasRole(Role::MAINTENANCE_ADMIN)) {
            throw new DomainException('A ticket can only be assigned to active maintenance staff.');
        }

        $status = $ticket->status;
        if (! in_array($status, [TicketStatus::Approved, TicketStatus::Assigned, TicketStatus::InProgress], true)) {
            throw new DomainException("Ticket {$ticket->ticket_no} is \"{$status->label()}\". It must be Approved before it can be assigned.");
        }

        if ($ticket->assigned_to !== null && (int) $ticket->assigned_to === (int) $assignee->id) {
            return $ticket;   // already theirs, nothing to do
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
            ['from_user_id' => $previous?->id, 'to_user_id' => $assignee->id],
        );
    }

    /** Board button [ACCEPT TASK]: take the ticket and start working on it. */
    public static function startWork(MaintenanceRequest $ticket, User $staff): MaintenanceRequest
    {
        if (! $staff->is_active || ! $staff->hasRole(Role::MAINTENANCE_ADMIN)) {
            throw new DomainException('Only maintenance staff can accept a task.');
        }

        if (! in_array($ticket->status, [TicketStatus::Approved, TicketStatus::Assigned], true)) {
            throw new DomainException($ticket->status->isPending() && $ticket->status !== TicketStatus::InProgress
                ? "Ticket {$ticket->ticket_no} is still \"{$ticket->status->label()}\" — it needs authorization before work can start."
                : "Ticket {$ticket->ticket_no} is \"{$ticket->status->label()}\" and cannot be accepted.");
        }

        if ($ticket->status === TicketStatus::Assigned && (int) $ticket->assigned_to !== (int) $staff->id) {
            throw new DomainException("This task is assigned to {$ticket->assignee?->name}.");
        }

        return DB::transaction(function () use ($ticket, $staff) {
            self::assign($ticket, $staff, $staff);   // Approved -> Assigned (no-op when already theirs)

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
        if (! $staff->is_active || ! $staff->hasRole(Role::MAINTENANCE_ADMIN)) {
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

    /** A free-text action note on the history. */
    public static function addNote(MaintenanceRequest $ticket, User $by, string $note): RequestStatusLog
    {
        if (blank($note)) {
            throw new DomainException('A note cannot be empty.');
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

    /** Validate the move, update the ticket and write ONE history line, atomically. */
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

        DB::transaction(function () use ($ticket, $to, $from, $attributes, $action, $by, $note, $meta) {
            if ($to !== null) {
                $attributes['status'] = $to;
            }

            $ticket->update($attributes);   // the model's own guards run here too

            $ticket->logEvent($action, $by, $note, $to !== null ? $from : null, $to, $meta);
        });

        return $ticket->refresh();
    }
}
