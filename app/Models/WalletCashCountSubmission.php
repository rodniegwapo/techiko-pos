<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletCashCountSubmission extends Model
{
    /** An optional count during the day, on Today's drawer. */
    public const SPOT_CHECK = 'spot_check';

    /** The store's final count, when a manager ends the day. */
    public const END_OF_DAY = 'end_of_day';

    /** A cashier's count when they end their own shift. */
    public const SHIFT = 'shift';

    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'counted_cash' => 'float',
        'expected_cash_snapshot' => 'float',
        'variance_snapshot' => 'float',
        'counted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }

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
