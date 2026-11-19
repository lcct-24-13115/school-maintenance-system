<?php

namespace App\Services;

use App\Mail\TicketNotificationMail;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Notifications\FormActivity;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells the requester the estimated completion date: one in-app alert (bell) and one queued email.
 * Never throws (a failed alert or email must not undo the estimate), never notifies the person who set it,
 * and a confidential ticket's email carries no ticket text (same rule as TicketMailer).
 */
class TicketEstimateNotice
{
    public static function send(MaintenanceRequest $ticket, User $by): void
    {
        $to = $ticket->requester;

        if ($to === null || ! $to->is_active || (int) $to->id === (int) $by->id || ! $ticket->isVisibleTo($to)) {
            return;
        }

        $date = TicketEstimate::date($ticket);
        $when = $date?->format('F j, Y') ?? 'a date to be confirmed';
        $headline = 'Estimated completion date';
        $intro = "{$by->name} set the estimated completion date of your request: {$when}.";

        try {
            Notification::send($to, new FormActivity([
                'event' => 'estimate_set',
                'form_type' => 'ticket',
                'form_label' => MaintenanceRequest::LABEL,
                'form_id' => $ticket->id,
                'reference_no' => $ticket->reference_no,
                'requester_name' => $to->name,
                'department' => $ticket->department?->name,
                'location' => $ticket->is_confidential ? null : $ticket->location,
                'urgent' => false,
                'actor_name' => $by->name,
                'title' => "{$by->name} set the estimated completion date: {$when}",
                'message' => $ticket->is_confidential ? '' : trim((string) $ticket->eta_message),
            ]));
        } catch (Throwable $e) {
            report($e);
        }

        if (! TicketMailer::enabled() || blank($to->email)) {
            return;
        }

        try {
            $confidential = (bool) $ticket->is_confidential;

            Mail::to($to)->queue(new TicketNotificationMail('estimate_set', [
                'name' => $to->name,
                'subject' => "[{$ticket->ticket_no}] {$headline}",
                'headline' => $headline,
                'intro' => $intro,
                'ticket_no' => $ticket->ticket_no,
                'status' => $ticket->status->label(),
                'details' => $confidential ? [] : array_filter([
                    'Location' => $ticket->location,
                    'Estimated completion' => $when,
                ], fn ($v) => filled($v)),
                'note' => ($confidential || blank($ticket->eta_message)) ? null : trim((string) $ticket->eta_message),
                'url' => route('tickets.show', $ticket),
                'confidential' => $confidential,
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
