<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A table in a store that serves dine-in: guests at it run a tab. */
class DiningTable extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'seats' => 'integer',
        'pos_x' => 'integer',
        'pos_y' => 'integer',
        'needs_cleaning_at' => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    /** The tabs open on this table (usually one; a split check makes two). */
    public function openTabs(): HasMany
    {
        return $this->hasMany(Sale::class)->openTabs();
    }

    public function scopeForLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }
}
