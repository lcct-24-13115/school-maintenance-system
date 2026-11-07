<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    protected $fillable = ['name', 'location', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function pmSchedules(): HasMany
    {
        return $this->hasMany(PmSchedule::class);
    }
}