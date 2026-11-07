<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    protected $table = 'equipment';

    protected $fillable = ['name', 'facility_id', 'category', 'serial_no', 'notes'];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function pmSchedules(): HasMany
    {
        return $this->hasMany(PmSchedule::class);
    }
}