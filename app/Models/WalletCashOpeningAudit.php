<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletCashOpeningAudit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'business_date' => 'date',
        'old_opening_cash' => 'float',
        'new_opening_cash' => 'float',
        'delta_amount' => 'float',
        'changed_at' => 'datetime',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(WalletCashReconciliation::class, 'reconciliation_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
