<?php

namespace App\Support\Wallet;

use App\Models\Sale;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;

final class WalletCashDailyExpected
{
    /**
     * @return array{
     *     opening_cash: float,
     *     paid_cash_sales: float,
     *     manual_in: float,
     *     manual_out: float,
     *     expected_cash: float
     * }
     */
    public static function compute(
        string $domainSlug,
        int $locationId,
        string $businessDate,
        ?WalletCashReconciliation $recon = null
    ): array {
        $openingCash = round((float) ($recon?->opening_cash ?? 0), 2);
        $openingBasisAt = $recon?->opening_basis_at;

        $salesQuery = Sale::query()
            ->where('domain', $domainSlug)
            ->where('location_id', $locationId)
            ->where('payment_status', 'paid')
            ->where('payment_method', 'cash')
            ->whereDate('transaction_date', $businessDate);

        $movementBase = WalletCashMovement::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('movement_date', $businessDate)
            ->where(function ($q) {
                $q->whereNull('notes')
                    ->orWhere('notes', 'not like', 'AUTO_CC_%');
            });

        if ($openingBasisAt !== null) {
            $salesQuery->where('transaction_date', '>=', $openingBasisAt);
            $movementBase->where('created_at', '>=', $openingBasisAt);
        }

        $paidCashSales = round((float) $salesQuery->sum('grand_total'), 2);
        $manualIn = round((float) (clone $movementBase)->where('direction', 'in')->sum('amount'), 2);
        $manualOut = round((float) (clone $movementBase)->where('direction', 'out')->sum('amount'), 2);
        $expectedCash = round($openingCash + $paidCashSales + $manualIn - $manualOut, 2);

        return [
            'opening_cash' => $openingCash,
            'paid_cash_sales' => $paidCashSales,
            'manual_in' => $manualIn,
            'manual_out' => $manualOut,
            'expected_cash' => $expectedCash,
        ];
    }
}
