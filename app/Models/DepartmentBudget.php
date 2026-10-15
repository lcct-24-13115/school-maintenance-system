<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentBudget extends Model
{
    protected $fillable = ['department_id', 'fiscal_year', 'allocated_amount', 'alert_threshold_percent'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function remaining(): float
    {
        return (float) $this->allocated_amount - $this->department->spentInYear($this->fiscal_year);
    }

    /** True when spending reached the alert threshold (default 80%). */
    public function isNearLimit(): bool
    {
        if ((float) $this->allocated_amount <= 0) {
            return false;
        }
        $used = $this->department->spentInYear($this->fiscal_year) / (float) $this->allocated_amount * 100;

        return $used >= $this->alert_threshold_percent;
    }
}
