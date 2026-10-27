<?php

namespace App\Models;

use App\Support\Wallet\WalletCashBridgeExpected;
use Illuminate\Database\Eloquent\Builder;
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

    /** Written only by the Expenses module; staff can't pick it in the ledger form. */
    public const KIND_EXPENSE = 'expense';

    /**
     * Lines other modules post on their own (an expense paid from the register, a supplier paid
     * in cash, other income put in the drawer). They can't be added or picked by hand in the
     * ledger; the record they belong to points at them through wallet_cash_movement_id.
     */
    /** Written only when a sale is completed with a cash/e-wallet payment. */
    public const KIND_SALE_PAYMENT = 'sale_payment';

    /** Written only when a sale with a posted sale_payment movement is voided. */
    public const KIND_SALE_VOID = 'sale_void';

    /** Written only when a customer pays their credit (utang) in cash. */
    public const KIND_CREDIT_PAYMENT = 'credit_payment';

    /** Cash the owner took from another store's drawer and put in this one (paired by transfer_group). */
    public const KIND_OWNER_TRANSFER_IN = 'owner_transfer_in';

    public const SYSTEM_KINDS = [
        self::KIND_EXPENSE,
        'supplier_payment',
        'other_income',
        'loan_received',
        'loan_payment',
        'asset_purchase',
        'owner_investment',
        self::KIND_SALE_PAYMENT,
        self::KIND_SALE_VOID,
        self::KIND_CREDIT_PAYMENT,
        self::KIND_OWNER_TRANSFER_IN,
    ];

    protected $casts = [
        'movement_date' => 'date',
        'amount' => 'float',
    ];

    /** A line someone adds while their own shift is open (dated within it) counts toward that shift's drawer. */
    protected static function booted(): void
    {
        static::creating(function (WalletCashMovement $movement) {
            if ($movement->register_shift_id || ! $movement->user_id || ! $movement->location_id || ! $movement->movement_date) {
                return;
            }
            $shift = RegisterShift::openFor((string) $movement->domain, (int) $movement->location_id, (int) $movement->user_id);
            if ($shift && $movement->movement_date->toDateString() >= $shift->business_date->toDateString()) {
                $movement->register_shift_id = $shift->id;
            }
        });
    }

    public function scopeForDomain($query, string $domainSlug)
    {
        return $query->where('domain', $domainSlug);
    }

    public function scopeForWalletContext($query, string $domainSlug, int $locationId)
    {
        return $query
            ->where('domain', $domainSlug)
            ->where('location_id', $locationId);
    }

    /**
     * Money in the cash drawer itself. A line with a payment channel moves that channel's money
     * (GCash, a bank account) and leaves the drawer's cash alone.
     */
    public function scopeInDrawer(Builder $query): Builder
    {
        return $query->whereNull('payment_card_type_id');
    }

    /**
     * Same rule as {@see WalletCashDailyExpected}: exclude book-only AUTO_CC_OPENING and
     * AUTO_CC_COUNTED_VARIANCE; still include end-shift cashout (cash left the drawer).
     */
    public function scopeForManualLedgerRunningBalance(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('notes')
                ->orWhere('notes', WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT)
                ->orWhere('notes', 'not like', 'AUTO_CC_%');
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
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
