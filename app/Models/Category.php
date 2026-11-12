<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Category extends Model
{
    public const TYPE_MATERIALS = 'materials';

    public const TYPE_EQUIPMENT = 'equipment';

    public const TYPE_SUPPLIES = 'supplies';

    /** Categories of every department except the Maintenance Office have no materials/equipment/supplies split. */
    public const TYPE_SERVICE = 'service';

    /** Name of the catch-all category (one per type, locked). Tickets of a deleted category move here. */
    public const OTHER = 'Other';

    protected $fillable = ['name', 'type', 'department_id', 'description', 'is_active', 'sort_order', 'is_hidden', 'is_locked'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_hidden' => 'boolean',
            'is_locked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return array<string, string> type => label, in display order */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_MATERIALS => 'Materials',
            self::TYPE_EQUIPMENT => 'Equipment',
            self::TYPE_SUPPLIES => 'Supplies',
        ];
    }

    protected static function booted(): void
    {
        // Until every caller names a department, a new category belongs to the Maintenance Office (as before Phase 2).
        static::creating(function (self $category) {
            $category->department_id ??= Department::maintenanceId();
        });
    }

    /**
     * The category groups (type => label) a department has. The Maintenance Office keeps Materials / Equipment / Supplies;
     * every other department has one plain list.
     *
     * @return array<string, string>
     */
    public static function typeLabelsFor(?int $departmentId): array
    {
        $departmentId = $departmentId === null ? null : (int) $departmentId;

        if ($departmentId === null || $departmentId === Department::maintenanceId()) {
            return self::typeLabels();
        }

        return [self::TYPE_SERVICE => 'Categories'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Only the categories of one department (null = categories that have no department). */
    public function scopeOfDepartment(Builder $query, ?int $departmentId): Builder
    {
        return $departmentId === null
            ? $query->whereNull('department_id')
            : $query->where('department_id', $departmentId);
    }

    /** Categories offered on new tickets (the repair form and its validation). Hidden ones are not. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->where('is_hidden', false);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }

    /** The locked "Other" category of a type inside a department (default: the Maintenance Office). Created on the spot when it does not exist yet. */
    public static function otherFor(string $type, ?int $departmentId = null): self
    {
        $departmentId ??= Department::maintenanceId();

        return self::firstOrCreate(
            ['name' => self::OTHER, 'type' => $type, 'department_id' => $departmentId],
            ['description' => 'Catch-all. Tickets of a deleted category move here.', 'sort_order' => 999, 'is_locked' => true],
        );
    }

    /**
     * Delete this category. Its tickets and inventory items move to the "Other" category of the SAME type
     * (so the rule "a typed ticket uses a category of its type" still holds). Locked categories cannot be deleted.
     *
     * @return int how many tickets were moved
     */
    public function deleteMovingTicketsToOther(): int
    {
        if ($this->is_locked) {
            throw new DomainException('This category is locked and cannot be deleted. Unlock it first.');
        }

        return DB::transaction(function (): int {
            $other = self::otherFor($this->type, $this->department_id);

            // withTrashed(): deleted (soft) tickets also point at this category and the foreign key would block the delete.
            // Plain UPDATE on purpose: the tickets keep their history and their request_type, and the model
            // rule that checks the type is satisfied because Other has the same type.
            // subcategory_id is cleared: a subcategory belongs to ONE category and "Other" has none of these.
            $moved = MaintenanceRequest::withTrashed()->where('category_id', $this->id)->update(['category_id' => $other->id, 'subcategory_id' => null]);
            Inventory::withTrashed()->where('category_id', $this->id)->update(['category_id' => $other->id]);

            $this->subcategories()->delete();
            $this->delete();

            return $moved;
        });
    }
}
