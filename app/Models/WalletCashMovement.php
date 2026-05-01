<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletCashMovement extends Model
{
    protected $guarded = [];

    public const KINDS = [
        'cash_sale_topup',
        'owner_draw',
        'ewallet_transfer_in',
        'ewallet_transfer_out',
        'adjustment',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'amount' => 'float',
    ];

    public function scopeForDomain($query, string $domainSlug)
    {
        return $query->where('domain', $domainSlug);
    }

    public function paymentCardType(): BelongsTo
    {
        return $this->belongsTo(PaymentCardType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Signed amount for balance: in positive, out negative. */
    public function signedAmount(): float
    {
        $amt = (float) $this->amount;

        return $this->direction === 'out' ? -$amt : $amt;
    }
}
