<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of Cash on hand: the business's cash the owner holds, out of every drawer (CashOnHand).
 * Collected, moved to a store and corrected lines only change where the money is; owner_use is
 * the one line that takes money out of the business (an owner withdrawal in the statements).
 */
class CashOnHandMovement extends Model
{
    /** From a shift a manager approved, or a store's end-of-day cash-out. */
    public const COLLECTED = 'collected';

    /** Back to a store's drawer or one of its payment channels. */
    public const TO_STORE = 'to_store';

    /** The owner kept it for themselves. */
    public const OWNER_USE = 'owner_use';

    /** A starting balance or a correction, in or out. */
    public const ADJUSTMENT = 'adjustment';

    public const KINDS = [self::COLLECTED, self::TO_STORE, self::OWNER_USE, self::ADJUSTMENT];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'movement_date' => 'date',
        'source_date' => 'date',
    ];

    public function scopeForDomain(Builder $query, string $domainSlug): Builder
    {
        return $query->where('domain', $domainSlug);
    }

    /** Signed amount for the balance: in positive, out negative. */
    public function signedAmount(): float
    {
        return $this->direction === 'out' ? -(float) $this->amount : (float) $this->amount;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }

    public function paymentCardType(): BelongsTo
    {
        return $this->belongsTo(PaymentCardType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
