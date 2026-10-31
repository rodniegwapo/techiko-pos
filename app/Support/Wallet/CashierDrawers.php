<?php

namespace App\Support\Wallet;

use App\Models\RegisterShift;
use App\Models\User;

/**
 * Each cashier's own drawer at a store today, for owners and managers on Where money is. Every
 * cashier has their own tray: on shift it holds what their shift's Expected says; after their
 * shift it holds what they counted, less what the owner collected to Cash on hand. Only each
 * person's latest shift counts: a next shift starts with the cash the last one left, so adding
 * them up would count it twice.
 *
 * What the store's drawer holds outside any shift (cash moved between shifts, a manager's cash
 * lines with no shift open, the end-of-day cash-out, and approved counts' differences booked into
 * the drawer) is the "rest": the store's Expected less every cashier's drawer, so the rows add up
 * to that figure.
 */
final class CashierDrawers
{
    /**
     * @return array{drawers: list<array{user: array{id: int, name: string}, shift_id: int, status: string, amount: float}>, rest: float, total: float}
     */
    public static function build(string $domainSlug, int $locationId, string $businessDate, float $storeExpected): array
    {
        $service = app(RegisterShiftService::class);
        $latest = RegisterShift::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('business_date', $businessDate)
            ->orderBy('opened_at')->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($shifts) => $shifts->last());
        $names = User::query()->whereIn('id', $latest->keys())->pluck('name', 'id');

        $drawers = $latest->map(fn (RegisterShift $shift, $userId) => [
            'user' => ['id' => (int) $userId, 'name' => (string) ($names[$userId] ?? 'Unknown user')],
            'shift_id' => $shift->id,
            'status' => $shift->status,
            'amount' => $shift->isOpen()
                ? $service->figures($shift)['expected_cash']
                : round((float) $shift->counted_cash - (float) $shift->collected_amount, 2),
        ])->sortBy('user.name')->values();

        $inDrawers = round((float) $drawers->sum('amount'), 2);

        return [
            'drawers' => $drawers->all(),
            'rest' => round($storeExpected - $inDrawers, 2),
            'total' => round($storeExpected, 2),
        ];
    }
}
