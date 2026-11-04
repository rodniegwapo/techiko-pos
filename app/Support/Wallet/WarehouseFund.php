<?php

namespace App\Support\Wallet;

use App\Models\InventoryLocation;
use App\Models\WalletCashMovement;
use Illuminate\Support\Collection;

/**
 * A warehouse's cash fund: cash the owner moved there from Cash on hand, to pay suppliers and
 * expenses at the warehouse. It is the warehouse's own ledger lines with no payment channel (the
 * same lines a store's drawer is made of), without shifts, counts or a day to close.
 */
class WarehouseFund
{
    /** Cash in the warehouse's fund now: every line in, less every line out. */
    public static function balance(string $domainSlug, int $locationId): float
    {
        $sum = WalletCashMovement::query()->forWalletContext($domainSlug, $locationId)->inDrawer()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN -amount ELSE amount END), 0) AS balance")
            ->value('balance');

        return round((float) $sum, 2);
    }

    /** The fund's latest lines, newest first, each with the balance after it. */
    public static function recent(string $domainSlug, int $locationId, int $limit = 20): Collection
    {
        $lines = WalletCashMovement::query()->forWalletContext($domainSlug, $locationId)->inDrawer()
            ->with('user:id,name')
            ->orderByDesc('movement_date')->orderByDesc('id')
            ->limit($limit)->get();

        $after = self::balance($domainSlug, $locationId);

        return $lines->map(function (WalletCashMovement $m) use (&$after) {
            $row = [
                'id' => $m->id,
                'date' => $m->movement_date?->toDateString(),
                'direction' => $m->direction,
                'amount' => (float) $m->amount,
                'kind' => $m->kind,
                'notes' => $m->notes,
                'by' => $m->user?->name,
                'balance_after' => $after,
            ];
            $after = round($after - $m->signedAmount(), 2);

            return $row;
        })->values();
    }

    /** Every active warehouse of the business, with its fund's balance. */
    public static function all(string $domainSlug): Collection
    {
        return InventoryLocation::query()->forDomain($domainSlug)->active()->where('type', 'warehouse')
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (InventoryLocation $w) => [
                'id' => (int) $w->id,
                'name' => (string) $w->name,
                'balance' => self::balance($domainSlug, (int) $w->id),
            ])->values();
    }

    /** Total cash in every warehouse's fund. */
    public static function total(string $domainSlug): float
    {
        return round((float) self::all($domainSlug)->sum('balance'), 2);
    }
}
