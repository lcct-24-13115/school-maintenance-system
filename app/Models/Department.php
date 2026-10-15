<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['name', 'code'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(DepartmentBudget::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function expenditures(): HasMany
    {
        return $this->hasMany(ReceiptExpenditure::class);
    }

    public function spentInYear(int $year): float
    {
        return (float) $this->expenditures()->whereYear('purchase_date', $year)->sum('amount');
    }
}
