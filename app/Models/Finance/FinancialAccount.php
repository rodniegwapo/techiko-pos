<?php

namespace App\Models\Finance;

use App\Models\PaymentCardType;
use App\Models\Sale;
use App\Models\SalePayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A bank or e-wallet account the business keeps money in. Its balance is what the owner reads
 * off the account on a given day (no bank feed). When the account is fed by a payment channel
 * (the GCash or bank channel customers pay through), sales paid through that channel after the
 * last entered balance are added on top of it.
 */
class FinancialAccount extends Model
{
    public const TYPES = ['bank', 'ewallet', 'other'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(FinancialAccountBalance::class)->orderByDesc('as_of_date')->orderByDesc('id');
    }

    public function latestBalance(): HasOne
    {
        return $this->hasOne(FinancialAccountBalance::class)->ofMany(['as_of_date' => 'max', 'id' => 'max']);
    }

    public function paymentCardType(): BelongsTo
    {
        return $this->belongsTo(PaymentCardType::class);
    }

    /**
     * The account's balance at the end of a day: the last balance entered on or before it, plus
     * sales received through the linked channel since then.
     *
     * @return array{balance: float|null, as_of: string|null, received: float}
     */
    public function positionOn(string $date): array
    {
        $entry = $this->balances()->where('as_of_date', '<=', $date)->first();
        if ($entry === null) {
            return ['balance' => null, 'as_of' => null, 'received' => 0.0];
        }

        $received = 0.0;
        $channel = $this->paymentCardType;
        if ($channel !== null) {
            $sales = Sale::query()
                ->where('domain', $this->domain)
                ->where('payment_status', 'paid')
                ->where('transaction_date', '>', Carbon::parse($entry->as_of_date)->endOfDay())
                ->where('transaction_date', '<=', Carbon::parse($date)->endOfDay());
            $received = SalePayment::totalFor($sales, PaymentCardType::methodForKind($channel->kind), $channel->id);
        }

        return [
            'balance' => round((float) $entry->balance + $received, 2),
            'as_of' => $entry->as_of_date->toDateString(),
            'received' => $received,
        ];
    }

    /** The balance at the end of a day (null when none was entered by then). */
    public function balanceOn(string $date): ?float
    {
        return $this->positionOn($date)['balance'];
    }
}
