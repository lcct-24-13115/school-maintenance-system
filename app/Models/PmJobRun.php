<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of the PM ticket generator (daily job, the command, or the "Ticket now" button). */
class PmJobRun extends Model
{
    protected $fillable = [
        'trigger', 'ran_by', 'started_at', 'finished_at',
        'schedules_checked', 'tickets_created', 'skipped', 'error_count', 'errors',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'errors' => 'array',
        ];
    }

    public function triggerLabel(): string
    {
        return match ($this->trigger) {
            'schedule' => 'Daily job',
            'manual' => 'Ticket now button',
            default => 'Command',
        };
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ran_by');
    }
}