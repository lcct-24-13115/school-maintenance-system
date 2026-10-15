<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FormApproval extends Model
{
    protected $fillable = [
        'approvable_type', 'approvable_id', 'step', 'step_order', 'label',
        'role_slug', 'user_id', 'status', 'signed_at', 'remarks',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
