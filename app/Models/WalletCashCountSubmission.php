<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletCashCountSubmission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'counted_cash' => 'float',
        'expected_cash_snapshot' => 'float',
        'variance_snapshot' => 'float',
        'counted_at' => 'datetime',
    ];

    public function scopeForWalletContext($query, string $domainSlug, int $locationId)
    {
        return $query
            ->where('domain', $domainSlug)
            ->where('location_id', $locationId);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(WalletCashReconciliation::class, 'reconciliation_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
