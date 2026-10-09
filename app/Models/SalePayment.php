<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One part of a sale paid in parts (a 'split' sale): cash, a card, an e-wallet, a bank or credit. */
class SalePayment extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'tendered' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentCardType(): BelongsTo
    {
        return $this->belongsTo(PaymentCardType::class);
    }

    /** What a part is called: its channel (GCash, BDO, Visa terminal) or else its method. */
    public function label(): string
    {
        return $this->paymentCardType?->name
            ?? ['e-wallet' => 'E-wallet'][$this->method] ?? ucfirst($this->method);
    }

    /** The parts of a split sale in one line, e.g. "Cash 200.00 + GCash 300.00". */
    public static function breakdown(iterable $payments): string
    {
        return collect($payments)
            ->map(fn (self $p) => $p->label().' '.number_format((float) $p->amount, 2))
            ->implode(' + ');
    }

    /** @return array{method: string, channel: ?string, label: string, reference: ?string, amount: float, tendered: ?float} */
    public function toDisplayArray(): array
    {
        return [
            'method' => $this->method,
            'channel' => $this->paymentCardType?->name,
            'label' => $this->label(),
            'reference' => $this->reference,
            'amount' => round((float) $this->amount, 2),
            'tendered' => $this->tendered !== null ? round((float) $this->tendered, 2) : null,
        ];
    }

    /**
     * Money taken one way (and through one channel, when given) by the sales in $sales: the whole of
     * each sale paid only that way, plus that way's part of each split sale. $sales must not already
     * be narrowed to a payment method.
     */
    public static function totalFor(Builder $sales, string $method, ?int $channelId = null): float
    {
        $whole = (clone $sales)
            ->where('payment_method', $method)
            ->when($channelId, fn ($q) => $q->where('payment_card_type_id', $channelId))
            ->sum('grand_total');

        $parts = static::query()
            ->whereIn('sale_id', (clone $sales)->where('payment_method', 'split')->select('sales.id'))
            ->where('method', $method)
            ->when($channelId, fn ($q) => $q->where('payment_card_type_id', $channelId))
            ->sum('amount');

        return round((float) $whole + (float) $parts, 2);
    }
}
