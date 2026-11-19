<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One signature line of a ticket's approval chain (Vice Principal -> Finance -> President). */
class TicketApproval extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'request_id', 'step_order', 'step', 'label', 'role_slug', 'user_id', 'status', 'amount', 'remarks', 'signed_at',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
