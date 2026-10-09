<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment channel at a store: a card terminal, an e-wallet (GCash, Maya…) or a bank account.
 * (Named for its first use, card types; `kind` says which.)
 */
class PaymentCardType extends Model
{
    public const KINDS = ['card', 'ewallet', 'bank'];

    /** Which kind of channel each sale payment method is paid through. */
    public const KIND_FOR_METHOD = [
        'card' => 'card',
        'e-wallet' => 'ewallet',
        'bank' => 'bank',
    ];

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** The sale payment method a channel of this kind is used for, e.g. ewallet → e-wallet. */
    public static function methodForKind(string $kind): string
    {
        return array_search($kind, self::KIND_FOR_METHOD, true) ?: 'card';
    }

    public function scopeForDomain($query, string $domainSlug)
    {
        return $query->where('domain', $domainSlug);
    }

    public function scopeForDomainLocation($query, string $domainSlug, int $locationId)
    {
        return $query->where('domain', $domainSlug)->where('location_id', $locationId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
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
