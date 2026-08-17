<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentCardType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeForDomain($query, string $domainSlug)
    {
        return $query->where('domain', $domainSlug);
    }

    public function scopeForDomainLocation($query, string $domainSlug, int $locationId)
    {
        return $query
            ->where('domain', $domainSlug)
            ->where('location_id', $locationId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
