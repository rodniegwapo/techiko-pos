<?php

namespace App\Support\Wallet;

use App\Models\RegisterShift;

/**
 * Today's drawer for supervisors and up: every cashier's shift at the store on a day, each with how
 * its Expected is reached (opening, cash sales, cash in and out), its count and how far off it was.
 * Today also lists shifts from an earlier day still open or ended today, marked, so none is missed.
 *
 * "In tray now" (today only) is what each cashier's tray holds (CashierDrawers): only on their
 * latest shift, since a next shift starts with the cash the last one left. Those trays plus the
 * store's cash outside any shift add up to the store's Expected.
 */
final class DrawerByShift
{
    /**
     * @return array{rows: list<array<string, mixed>>, store_rest: float|null, total: float}
     */
    public static function build(string $domainSlug, int $locationId, string $businessDate, float $storeExpected): array
    {
        $service = app(RegisterShiftService::class);
        $isToday = $businessDate === now()->toDateString();
        $shifts = RegisterShift::query()
            ->forWalletContext($domainSlug, $locationId)
            ->where(fn ($q) => $q->whereDate('business_date', $businessDate)
                // Today also lists a shift from an earlier day that is still open (someone forgot to
                // end it) or was ended today, so it isn't missed: its money is that day's, not today's.
                ->when($isToday, fn ($q) => $q->orWhere(fn ($q) => $q
                    ->whereDate('business_date', '<', $businessDate)
                    ->where(fn ($q) => $q->where('status', RegisterShift::OPEN)->orWhereDate('closed_at', $businessDate)))))
            ->with(['user:id,name', 'reviewedBy:id,name', 'collectedBy:id,name'])
            ->withCount('reopenLogs')
            ->orderBy('opened_at')->orderBy('id')
            ->get();

        $trays = $isToday ? collect(CashierDrawers::build($domainSlug, $locationId, $businessDate, $storeExpected)['drawers'])->keyBy('shift_id') : collect();
        $rest = $isToday ? round($storeExpected - (float) $trays->sum('amount'), 2) : null;

        $rows = $shifts->map(function (RegisterShift $shift) use ($service, $trays) {
            $figures = $service->figures($shift);

            return [
                ...$shift->toRow(),
                'reopen_count' => (int) $shift->reopen_logs_count,
                'paid_cash_sales' => $figures['paid_cash_sales'],
                'manual_in' => $figures['manual_in'],
                'manual_out' => $figures['manual_out'],
                // Open: what should be there now. Ended: what it was counted against.
                'expected_cash' => $shift->isOpen() ? $figures['expected_cash'] : (float) $shift->expected_cash,
                'in_tray' => isset($trays[$shift->id]) ? $trays[$shift->id]['amount'] : null,
                // Started on an earlier day: shown for follow-up, not part of today's figures.
                'earlier_day' => $shift->business_date->toDateString() < now()->toDateString(),
                'last_spot_check' => RegisterShiftService::lastSpotCheck($shift),
            ];
        })->values();

        return [
            'rows' => $rows->all(),
            'store_rest' => $rest,
            'total' => round($storeExpected, 2),
        ];
    }
}
