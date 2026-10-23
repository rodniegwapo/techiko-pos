<?php

namespace App\Support\Wallet;

use App\Models\Domain;
use App\Models\InventoryLocation;

/**
 * Where the money is in every store at once, for owners: each store's cash drawer (today's
 * Expected, as on Today's drawer) and its payment channels (as on Where money is).
 */
class AllStoresMoney
{
    /**
     * @return array{stores: list<array{id: int, name: string, drawer: float, channels: list<array>, total: float}>, total: float}
     */
    public static function build(Domain $domain, int $viewerId): array
    {
        $stores = InventoryLocation::query()->forDomain($domain->name_slug)->active()
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
            ->orderBy('name')
            ->get();

        $rows = $stores->map(function (InventoryLocation $store) use ($domain, $viewerId) {
            $drawer = (float) CashControlSnapshot::build($domain, $store, now()->toDateString(), $viewerId)['expected_cash'];
            $channels = collect(ChannelBalances::forLocation($domain->name_slug, (int) $store->id))
                ->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'kind' => $c['kind'], 'balance' => (float) $c['balance']])
                ->values()->all();

            return [
                'id' => (int) $store->id,
                'name' => $store->name,
                'drawer' => round($drawer, 2),
                'channels' => $channels,
                'total' => round($drawer + array_sum(array_column($channels, 'balance')), 2),
            ];
        })->values()->all();

        return ['stores' => $rows, 'total' => round(array_sum(array_column($rows, 'total')), 2)];
    }
}
