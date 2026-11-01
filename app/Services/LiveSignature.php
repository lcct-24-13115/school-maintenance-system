<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\User;

/**
 * Phase 15: a short fingerprint of "what this person can see right now".
 * The open page remembers the fingerprint it was drawn with and asks /live every few seconds;
 * when the answer is different, something changed and the page reloads.
 *
 * Everything goes through visibleTo(), so the fingerprint of a user never moves because of a ticket
 * (or an internal note) that user may not see. It is a hash, so it carries no ticket data.
 */
class LiveSignature
{
    /** $ticketId = 0: lists and dashboards (all visible tickets). $ticketId > 0: one ticket page. */
    public static function for(User $user, int $ticketId = 0): string
    {
        return $ticketId > 0 ? self::forTicket($user, $ticketId) : self::forList($user);
    }

    public static function forList(User $user): string
    {
        $terminal = TicketStatus::terminalValues();
        $in = implode(',', array_fill(0, count($terminal), '?'));

        $row = MaintenanceRequest::query()
            ->visibleTo($user)
            ->selectRaw(
                "COUNT(*) AS n, MAX(updated_at) AS m, MAX(id) AS i,
                 SUM(CASE WHEN status IN ($in) THEN 1 ELSE 0 END) AS f,
                 SUM(CASE WHEN is_emergency = 1 THEN 1 ELSE 0 END) AS e",
                $terminal
            )
            ->toBase()
            ->first();

        return md5(implode('|', ['list', $row->n ?? 0, $row->m ?? '', $row->i ?? 0, $row->f ?? 0, $row->e ?? 0]));
    }

    /** Empty string when the ticket does not exist or this user may not see it (the page then never polls). */
    public static function forTicket(User $user, int $ticketId): string
    {
        $ticket = MaintenanceRequest::query()->visibleTo($user)->whereKey($ticketId)->first();

        if (! $ticket) {
            return '';
        }

        $conversation = $ticket->conversationFor($user);   // internal notes only for staff, like the page

        return md5(implode('|', [
            'ticket',
            $ticket->status->value,
            $ticket->updated_at?->timestamp,
            $ticket->assigned_to,
            (int) $ticket->is_emergency,
            $conversation->count(),
            (int) $conversation->max('id'),
            $ticket->attachments()->count(),
            $ticket->statusLogs()->count(),
        ]));
    }
}
