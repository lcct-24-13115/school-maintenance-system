<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An optional sub-type of a Category, e.g. Plumbing > Leaking faucet. */
class Subcategory extends Model
{
    protected $fillable = ['category_id', 'name', 'description', 'sort_order', 'is_hidden'];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Offered on new tickets (the repair form and its validation). Hidden ones are not. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'subcategory_id');
    }
}
