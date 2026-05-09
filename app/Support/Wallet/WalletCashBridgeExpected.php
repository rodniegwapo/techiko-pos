<?php

namespace App\Support\Wallet;

use App\Models\Sale;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Bridge" expected cash: last attested counted amount plus per-day cash sales and
 * physically meaningful movements for each day after the anchor through the target date.
 *
 * Daily expected uses manual sums that exclude all AUTO_CC_% notes; bridge differs by
 * still counting AUTO_CC_ENDSHIFT_CASHOUT (cash left the drawer) while excluding only
 * AUTO_CC_OPENING and AUTO_CC_COUNTED_VARIANCE (book-only).
 */
final class WalletCashBridgeExpected
{
    public const NOTE_OPENING = 'AUTO_CC_OPENING';

    public const NOTE_COUNTED_VARIANCE = 'AUTO_CC_COUNTED_VARIANCE';

    public const NOTE_ENDSHIFT_CASHOUT = 'AUTO_CC_ENDSHIFT_CASHOUT';

    public const MAX_SPAN_DAYS_WARNING = 366;

    /**
     * Scope movements that affect physical cash for bridge deltas (per movement_date).
     */
    public static function physicalMovementBase(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('notes')
                ->orWhere('notes', self::NOTE_ENDSHIFT_CASHOUT)
                ->orWhere('notes', 'not like', 'AUTO_CC_%');
        });
    }

    /**
     * @return array{
     *     bridge_anchor_business_date: string|null,
     *     bridge_anchor_counted_cash: float|null,
     *     bridge_expected_cash: float|null,
     *     bridge_variance: float|null,
     *     bridge_day_span: int|null,
     *     bridge_span_warning: bool
     * }
     */
    public static function compute(
        string $domainSlug,
        int $locationId,
        string $businessDate,
        ?float $countedCashOnBusinessDate,
    ): array {
        $anchor = WalletCashReconciliation::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('business_date', '<', $businessDate)
            ->whereNotNull('counted_cash')
            ->orderByDesc('business_date')
            ->first();

        if ($anchor === null) {
            return [
                'bridge_anchor_business_date' => null,
                'bridge_anchor_counted_cash' => null,
                'bridge_expected_cash' => null,
                'bridge_variance' => null,
                'bridge_day_span' => null,
                'bridge_span_warning' => false,
            ];
        }

        $anchorDate = $anchor->business_date instanceof Carbon
            ? $anchor->business_date->toDateString()
            : (string) $anchor->business_date;
        $anchorCounted = round((float) $anchor->counted_cash, 2);

        $rangeStart = Carbon::parse($anchorDate)->addDay()->toDateString();
        $rangeEnd = $businessDate;

        if ($rangeStart > $rangeEnd) {
            $bridgeExpected = $anchorCounted;
            $daySpan = 0;
        } else {
            $period = CarbonPeriod::create($rangeStart, $rangeEnd);
            $daySpan = iterator_count($period);

            $salesByDay = self::paidCashSalesGroupedByDay($domainSlug, $locationId, $rangeStart, $rangeEnd);
            $movementNetByDay = self::physicalMovementNetGroupedByDay($domainSlug, $locationId, $rangeStart, $rangeEnd);

            $delta = 0.0;
            foreach ($period as $day) {
                $d = $day->toDateString();
                $delta += ($salesByDay[$d] ?? 0.0) + ($movementNetByDay[$d] ?? 0.0);
            }

            $bridgeExpected = round($anchorCounted + $delta, 2);
        }

        $bridgeVariance = $countedCashOnBusinessDate === null
            ? null
            : round(round((float) $countedCashOnBusinessDate, 2) - $bridgeExpected, 2);

        return [
            'bridge_anchor_business_date' => $anchorDate,
            'bridge_anchor_counted_cash' => $anchorCounted,
            'bridge_expected_cash' => $bridgeExpected,
            'bridge_variance' => $bridgeVariance,
            'bridge_day_span' => $daySpan,
            'bridge_span_warning' => $daySpan > self::MAX_SPAN_DAYS_WARNING,
        ];
    }

    /**
     * @return array<string, float> date Y-m-d => sum
     */
    private static function paidCashSalesGroupedByDay(
        string $domainSlug,
        int $locationId,
        string $rangeStart,
        string $rangeEnd,
    ): array {
        $dayExpr = self::sqlDateColumn('transaction_date');

        $rows = Sale::query()
            ->where('domain', $domainSlug)
            ->where('location_id', $locationId)
            ->where('payment_status', 'paid')
            ->where('payment_method', 'cash')
            ->whereDate('transaction_date', '>=', $rangeStart)
            ->whereDate('transaction_date', '<=', $rangeEnd)
            ->selectRaw("{$dayExpr} as day")
            ->selectRaw('SUM(grand_total) as total')
            ->groupBy(DB::raw($dayExpr))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->day] = (float) $row->total;
        }

        return $out;
    }

    /**
     * @return array<string, float> date Y-m-d => signed net (in minus out)
     */
    private static function physicalMovementNetGroupedByDay(
        string $domainSlug,
        int $locationId,
        string $rangeStart,
        string $rangeEnd,
    ): array {
        $dayExpr = self::sqlDateColumn('movement_date');
        $netExpr = "SUM(CASE WHEN direction = 'in' THEN amount ELSE -1 * amount END)";

        $q = WalletCashMovement::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('movement_date', '>=', $rangeStart)
            ->whereDate('movement_date', '<=', $rangeEnd);

        self::physicalMovementBase($q);

        $rows = $q
            ->selectRaw("{$dayExpr} as day")
            ->selectRaw("{$netExpr} as net")
            ->groupBy(DB::raw($dayExpr))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->day] = (float) $row->net;
        }

        return $out;
    }

    private static function sqlDateColumn(string $column): string
    {
        $driver = Sale::query()->getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            default => "DATE({$column})",
        };
    }
}
