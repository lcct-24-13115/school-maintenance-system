<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One line of the audit trail (Merge Phase M3). Append-only: a row can be created, never changed or deleted
 * through the model. (Written ONLY through App\Services\AuditLogger.)
 *
 * The database foreign key sets user_id to null if a user is ever removed, which is the one allowed change.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'action', 'description', 'subject_type', 'subject_id', 'meta', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are read-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are read-only and cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The thing the event is about (a ticket, a user, ...). Null when there is none or it was removed. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
