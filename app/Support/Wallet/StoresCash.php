<?php

namespace App\Support\Wallet;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * What the stores have already processed, for the warehouse: each closed shift with its last
 * count (counted, expected, difference, by whom) and the cash taken out at end of shift.
 * Read-only; closing a shift resets its count, so the figures come from the count history.
 */
class StoresCash
{
    /**
     * @param  int|null  $locationId  One store only (its store counts on Shifts & counts); all stores when null.
     * @return array<string, mixed>
     */
    public static function build(Request $request, Domain $domain, ?int $locationId = null): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = Carbon::parse($validated['date_from'] ?? now()->subDays(6))->toDateString();
        $to = Carbon::parse($validated['date_to'] ?? now())->toDateString();

        $stores = InventoryLocation::query()->forDomain($domain->name_slug)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
            ->when($locationId, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')
            ->get(['id', 'name']);

        $shifts = WalletCashReconciliation::query()
            ->where('wallet_cash_reconciliations.domain', $domain->name_slug)
            ->whereIn('wallet_cash_reconciliations.location_id', $stores->pluck('id'))
            ->where('wallet_cash_reconciliations.is_closed', true)
            ->whereDate('wallet_cash_reconciliations.business_date', '>=', $from)
            ->whereDate('wallet_cash_reconciliations.business_date', '<=', $to)
            ->leftJoin('users as closer', 'closer.id', '=', 'wallet_cash_reconciliations.closed_by')
            ->orderByDesc('wallet_cash_reconciliations.business_date')
            ->get(['wallet_cash_reconciliations.*', 'closer.name as closed_by_name']);

        $lastCounts = WalletCashCountSubmission::query()
            ->whereIn('reconciliation_id', $shifts->pluck('id'))
            ->with('countedBy:id,name')
            ->orderBy('counted_at')
            ->get()
            ->keyBy('reconciliation_id');

        $cashOuts = WalletCashMovement::query()
            ->where('domain', $domain->name_slug)
            ->whereIn('location_id', $stores->pluck('id'))
            ->where('notes', WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT)
            ->whereDate('movement_date', '>=', $from)
            ->whereDate('movement_date', '<=', $to)
            ->get(['location_id', 'movement_date', 'amount'])
            ->groupBy(fn ($m) => $m->location_id.'|'.$m->movement_date->toDateString())
            ->map(fn ($rows) => round((float) $rows->sum('amount'), 2));

        $names = $stores->pluck('name', 'id');
        $rows = $shifts->map(function (WalletCashReconciliation $shift) use ($lastCounts, $cashOuts, $names) {
            $day = $shift->business_date->toDateString();
            $count = $lastCounts->get($shift->id);

            return [
                'id' => $shift->id,
                'store' => $names[$shift->location_id] ?? '—',
                'location_id' => (int) $shift->location_id,
                'business_date' => $day,
                'counted_cash' => $count?->counted_cash,
                'expected_cash' => $count?->expected_cash_snapshot,
                'variance' => $count?->variance_snapshot,
                'counted_by' => $count?->countedBy?->name,
                'cash_out' => $cashOuts->get($shift->location_id.'|'.$day, 0.0),
                'closed_at' => $shift->closed_at?->toDateTimeString(),
                'closed_by' => $shift->closed_by_name,
            ];
        })->values();

        return [
            'rows' => $rows,
            'totals' => [
                'shifts' => $rows->count(),
                'counted' => round((float) $rows->sum('counted_cash'), 2),
                'cash_out' => round((float) $rows->sum('cash_out'), 2),
                'variance' => round((float) $rows->sum('variance'), 2),
            ],
            'filters' => ['date_from' => $from, 'date_to' => $to],
        ];
    }
}
