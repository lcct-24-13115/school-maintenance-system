<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal history of a ticket: status changes, assignments, reassignments, emergency flags,
 * authorizations, notes. Never show this on the public announcements board.
 */
class RequestStatusLog extends Model
{
    public const ACTION_STATUS = 'status_change';

    public const ACTION_ASSIGNMENT = 'assignment';

    public const ACTION_REASSIGNMENT = 'reassignment';

    public const ACTION_EMERGENCY = 'emergency_flag';

    public const ACTION_AUTHORIZATION = 'authorization';

    public const ACTION_NOTE = 'note';

    protected $fillable = ['request_id', 'user_id', 'action', 'from_status', 'to_status', 'note', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
