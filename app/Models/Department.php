<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    /** The office that handled every ticket before the system became multi-department. */
    public const MAINTENANCE_CODE = 'MNT';

    protected $fillable = ['name', 'code', 'is_active', 'is_service_department', 'head_user_id', 'description'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_service_department' => 'boolean',
        ];
    }

    /** Switched-on departments only (hidden ones keep their old tickets). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Departments that RECEIVE tickets. */
    public function scopeService(Builder $query): Builder
    {
        return $query->where('is_service_department', true);
    }

    /** id of the Maintenance Office, or null when it is not seeded yet. */
    public static function maintenanceId(): ?int
    {
        $id = static::query()->where('code', self::MAINTENANCE_CODE)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** Tickets this department RECEIVES (assigned_department_id). `requests()` below = tickets it FILED. */
    public function receivedTickets(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'assigned_department_id');
    }

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
