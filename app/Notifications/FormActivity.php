<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification type for everything: "new form filed", "signed", "rejected", "task accepted"...
 * Saved in the `notifications` table AND broadcast live (Reverb/Pusher). It is deliberately NOT queued,
 * so it goes out instantly without a queue worker running.
 */
class FormActivity extends Notification
{
    public function __construct(private array $payload) {}

    public function via(object $notifiable): array
    {
        return config('broadcasting.default') === 'null' ? ['database'] : ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $url = (in_array($this->payload['form_type'], ['repair', 'ticket'], true) && $notifiable->isMaintenanceTeam())
            ? route('maintenance.board')
            : route('notifications.index');

        if (($this->payload['form_type'] ?? null) === 'facility' && ! empty($this->payload['form_id'])) {
            $url = route('facilities.show', $this->payload['form_id']);
        }
        if (($this->payload['form_type'] ?? null) === 'requisition' && ! empty($this->payload['form_id'])) {
            $url = route('requisitions.show', $this->payload['form_id']);
        }
        return $this->payload + ['url' => $url];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
