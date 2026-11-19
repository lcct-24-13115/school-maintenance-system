<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One signature line of a ticket's approval chain (Vice Principal -> Finance -> President). */
class TicketApproval extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'request_id', 'step_order', 'step', 'label', 'role_slug', 'approver_id', 'user_id', 'status', 'amount', 'remarks', 'signed_at',
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

    /** The one person named for this step (the student's adviser), or null when anyone with the step's role may sign. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** Steps that $user may sign: named for them, or (no named person) held by their role. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('approver_id', $user->id)
                ->orWhere(fn (Builder $r) => $r->whereNull('approver_id')->where('role_slug', $user->role?->slug));
        });
    }

    /** Who is told it is their turn: the named person, or every active user of the step's role. @return Collection<int, User> */
    public function approvers(): Collection
    {
        return User::query()->where('is_active', true)
            ->when(
                $this->approver_id !== null,
                fn ($q) => $q->whereKey($this->approver_id),
                fn ($q) => $q->whereHas('role', fn ($r) => $r->where('slug', $this->role_slug)),
            )
            ->get();
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
