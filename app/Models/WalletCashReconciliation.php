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

    /** Days whose cash at the end is known: counted, or ended. */
    public function scopeWhereCashKnown($query)
    {
        return $query->where(fn ($q) => $q->whereNotNull('counted_cash')->orWhere('is_closed', true));
    }

    /**
     * The cash the day ended with, for the next day to start from. Ending a day leaves what stays in
     * the drawer as its opening (the float kept, or nothing after a cash-out); otherwise its count.
     */
    public function cashLeft(): float
    {
        return round((float) ($this->is_closed ? $this->opening_cash : $this->counted_cash), 2);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
