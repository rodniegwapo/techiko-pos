<?php

namespace App\Support\Wallet;

use App\Models\PaymentCardType;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\WalletCashMovement;
use Illuminate\Support\Collection;

/**
 * What each payment channel of a store (GCash, a bank account, a card terminal) holds now: its
 * wallet ledger lines (e-wallet sales, transfers, withdrawals, adjustments) plus card and bank
 * sales through it, which never post a ledger line. All-time; a "set actual balance" adjustment
 * is how a channel's starting money gets in.
 */
class ChannelBalances
{
    /**
     * @return Collection<int, array{id: int, name: string, kind: string, is_active: bool, balance: float}>
     */
    public static function forLocation(string $domainSlug, int $locationId): Collection
    {
        $channels = PaymentCardType::query()
            ->forDomainLocation($domainSlug, $locationId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'is_active']);

        if ($channels->isEmpty()) {
            return collect();
        }

        $ids = $channels->pluck('id');

        $ledgerNet = WalletCashMovement::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereIn('payment_card_type_id', $ids)
            ->groupBy('payment_card_type_id')
            ->selectRaw("payment_card_type_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net")
            ->pluck('net', 'payment_card_type_id');

        // Card and bank sales don't post ledger lines; e-wallet ones already did.
        $sales = Sale::query()
            ->where('domain', $domainSlug)
            ->where('location_id', $locationId)
            ->where('payment_status', 'paid')
            ->whereIn('payment_method', ['card', 'bank'])
            ->whereIn('payment_card_type_id', $ids)
            ->groupBy('payment_card_type_id')
            ->selectRaw('payment_card_type_id, SUM(grand_total) AS total')
            ->pluck('total', 'payment_card_type_id');

        $splitParts = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.domain', $domainSlug)
            ->where('sales.location_id', $locationId)
            ->where('sales.payment_status', 'paid')
            ->where('sales.payment_method', 'split')
            ->whereIn('sale_payments.method', ['card', 'bank'])
            ->whereIn('sale_payments.payment_card_type_id', $ids)
            ->groupBy('sale_payments.payment_card_type_id')
            ->selectRaw('sale_payments.payment_card_type_id, SUM(sale_payments.amount) AS total')
            ->pluck('total', 'payment_card_type_id');

        return $channels->map(fn (PaymentCardType $c) => [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'kind' => (string) ($c->kind ?? 'card'),
            'is_active' => (bool) $c->is_active,
            'balance' => round(
                (float) ($ledgerNet[$c->id] ?? 0) + (float) ($sales[$c->id] ?? 0) + (float) ($splitParts[$c->id] ?? 0),
                2
            ),
        ])->values();
    }
}
