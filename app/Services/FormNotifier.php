<?php

namespace App\Services;

use App\Models\FacilityRequest;
use App\Models\FormApproval;
use App\Models\MaintenanceRequest;
use App\Models\RepairRequest;
use App\Models\RequisitionSlip;
use App\Models\Role;
use App\Models\User;
use App\Notifications\FormActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Decides WHO gets alerted and WHAT it says. Call it from controllers after saving.
 * It never throws: if the WebSocket server is down the form is still saved (polling still alerts people).
 */
class FormNotifier
{
    /** A new form was filed: maintenance staff + the first approver are alerted. */
    public static function submitted(Model $form): void
    {
        $recipients = self::usersWithRole(Role::MAINTENANCE_ADMIN);

        // The 3 school forms have a signature chain; tickets do not (yet - Phase 3).
        if (method_exists($form, 'nextApproval')) {
            $recipients = $recipients->merge(self::approversFor($form->nextApproval(), $form));
        }

        self::send($recipients, $form, 'submitted', $form->requester,
            'filed a new '.$form::LABEL);
    }

    /** Someone signed (or rejected) a line. Requester + next approver are alerted. */
    public static function signed(Model $form, User $actor, FormApproval $line, ?string $note = null): void
    {
        $rejected = $line->status === 'rejected';
        $recipients = collect([$form->requester]);

        if (! $rejected) {
            $recipients = $recipients->merge(self::approversFor($form->nextApproval(), $form));

            if ($form->isPresidentApproved()) {   // fully approved
                $recipients = $recipients->merge(self::usersWithRole(Role::MAINTENANCE_ADMIN));
                if ($form instanceof FacilityRequest) {
                    $recipients = $recipients->merge(self::usersWithRole(Role::CUSTODIAN));
                }
            }
        }

        $event = $rejected ? 'rejected' : ($form->isPresidentApproved() ? 'approved' : 'signed');
        $verb = $rejected ? 'rejected' : ($event === 'approved' ? 'gave FINAL approval to' : 'signed');

        self::send($recipients, $form, $event, $actor, "{$verb} {$form->reference_no}".($note ? " — {$note}" : ''));
    }

    /** Maintenance tapped [Accept Task] / [Mark Completed] on a ticket (or the old repair form). */
    public static function work(Model $form, User $actor, string $event): void
    {
        $noun = $form instanceof MaintenanceRequest ? 'ticket' : 'repair request';
        $text = $event === 'accepted' ? "accepted your {$noun} and will work on it" : "finished the work on your {$noun}";

        self::send(collect([$form->requester]), $form, $event, $actor, $text);
    }

    // ---------------------------------------------------------------------------------------------

    private static function send(Collection $recipients, Model $form, string $event, User $actor, string $text): void
    {
        $recipients = $recipients->filter()->unique('id')
            ->reject(fn (User $u) => $u->id === $actor->id)
            ->filter(fn (User $u) => $u->is_active);

        if ($recipients->isEmpty()) {
            return;
        }

        $urgent = ($form instanceof MaintenanceRequest || $form instanceof RepairRequest) && $form->isEmergency();

        $payload = [
            'event' => $event,
            'form_type' => self::typeOf($form),
            'form_label' => $form::LABEL,
            'form_id' => $form->id,
            'reference_no' => $form->reference_no,
            'requester_name' => $form->requester->name,
            'department' => $form->department?->name,
            'location' => ($form instanceof RepairRequest || $form instanceof MaintenanceRequest) ? $form->location : null,
            'urgent' => $urgent,
            'actor_name' => $actor->name,
            'title' => ($urgent ? 'EMERGENCY: ' : '').$actor->name.' '.$text,
            'message' => self::summary($form),
        ];

        try {
            Notification::send($recipients, new FormActivity($payload));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function summary(Model $form): string
    {
        return match (true) {
            $form instanceof MaintenanceRequest => "{$form->location} — ".str($form->description)->limit(80),
            $form instanceof RepairRequest => "{$form->location} — ".str($form->job_description)->limit(80),
            $form instanceof FacilityRequest => str($form->purpose)->limit(80),
            $form instanceof RequisitionSlip => 'Needed '.$form->date_needed->format('M j, Y'),
            default => '',
        };
    }

    private static function typeOf(Model $form): string
    {
        return match (true) {
            $form instanceof MaintenanceRequest => 'ticket',
            $form instanceof RepairRequest => 'repair',
            $form instanceof FacilityRequest => 'facility',
            default => 'requisition',
        };
    }

    private static function usersWithRole(string $slug, ?int $departmentId = null): Collection
    {
        return User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', $slug))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->get();
    }

    /** Who must sign the given line. Dept. Head = only the head of the requesting department. */
    private static function approversFor(?FormApproval $line, Model $form): Collection
    {
        if (! $line) {
            return collect();
        }

        return self::usersWithRole($line->role_slug, $line->role_slug === Role::DEPARTMENT_HEAD ? $form->department_id : null);
    }
}
