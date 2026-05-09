<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletCashReconciliation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'opening_cash' => 'float',
        'opening_source_date' => 'date',
        'opening_basis_at' => 'datetime',
        'counted_cash' => 'float',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'counted_at' => 'datetime',
    ];

    public function scopeForWalletContext($query, string $domainSlug, int $locationId)
    {
        return $query
            ->where('domain', $domainSlug)
            ->where('location_id', $locationId);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
