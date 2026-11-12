<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One e-mail the system tried to send. Written only by App\Services\EmailLogger.
 */
class EmailLog extends Model
{
    public const SENDING = 'sending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    /** Shown instead of "sending" when no answer came back for this many minutes (computed, never stored). */
    public const UNCONFIRMED = 'unconfirmed';

    public const STALE_MINUTES = 10;

    protected $fillable = [
        'status', 'type', 'to_email', 'to_name', 'user_id', 'subject', 'ticket_no', 'mailer',
        'message_id', 'error', 'body_html', 'attempts', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** delivered | failed | sending | unconfirmed */
    public function effectiveStatus(): string
    {
        if ($this->status === self::SENDING && $this->created_at?->lt(now()->subMinutes(self::STALE_MINUTES))) {
            return self::UNCONFIRMED;
        }

        return (string) $this->status;
    }

    public function statusLabel(): string
    {
        return match ($this->effectiveStatus()) {
            self::DELIVERED => 'Delivered',
            self::FAILED => 'Failed',
            self::UNCONFIRMED => 'Not confirmed',
            default => 'Sending',
        };
    }

    /** Only ticket e-mails keep their body, so only they can be sent again. Delivered ones never need it. */
    public function canRetry(): bool
    {
        return filled($this->body_html)
            && in_array($this->effectiveStatus(), [self::FAILED, self::UNCONFIRMED], true);
    }

    public function scopeWithEffectiveStatus(Builder $query, string $status): Builder
    {
        $cut = now()->subMinutes(self::STALE_MINUTES);

        return match ($status) {
            self::DELIVERED => $query->where('status', self::DELIVERED),
            self::FAILED => $query->where('status', self::FAILED),
            self::SENDING => $query->where('status', self::SENDING)->where('created_at', '>=', $cut),
            self::UNCONFIRMED => $query->where('status', self::SENDING)->where('created_at', '<', $cut),
            default => $query,
        };
    }
}
