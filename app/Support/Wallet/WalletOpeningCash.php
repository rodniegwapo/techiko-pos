<?php

namespace App\Support\Wallet;

use App\Models\InventoryLocation;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;

/**
 * The store day's opening cash: saving it keeps an audit row of the change and the book-only
 * AUTO_CC_OPENING ledger line in step.
 */
final class WalletOpeningCash
{
    public const LEDGER_NOTE = 'AUTO_CC_OPENING';

    public static function save(string $domainSlug, InventoryLocation $location, string $businessDate, float $amount, int $userId, ?string $reason): WalletCashReconciliation
    {
        $newOpening = round($amount, 2);
        $recon = WalletCashReconciliation::query()->firstOrNew([
            'domain' => $domainSlug,
            'location_id' => $location->id,
            'business_date' => $businessDate,
        ]);
        $oldOpening = $recon->exists ? (float) $recon->opening_cash : null;

        $recon->opening_cash = $newOpening;
        $recon->opening_source = 'manual';
        $recon->opening_source_date = null;
        $recon->save();

        WalletCashOpeningAudit::query()->create([
            'domain' => $domainSlug,
            'location_id' => $location->id,
            'business_date' => $businessDate,
            'reconciliation_id' => $recon->id,
            'old_opening_cash' => $oldOpening,
            'new_opening_cash' => $newOpening,
            'delta_amount' => round($newOpening - (float) ($oldOpening ?? 0), 2),
            'changed_by' => $userId,
            'changed_at' => now(),
            'reason' => $reason,
        ]);

        self::syncLedgerLine($domainSlug, $location, $businessDate, $newOpening, $userId);

        return $recon;
    }

    public static function syncLedgerLine(string $domainSlug, InventoryLocation $location, string $businessDate, float $openingAmount, int $userId): void
    {
        WalletCashMovement::query()->updateOrCreate(
            [
                'domain' => $domainSlug,
                'location_id' => $location->id,
                'movement_date' => $businessDate,
                'kind' => 'adjustment',
                'notes' => self::LEDGER_NOTE,
            ],
            [
                'payment_card_type_id' => null,
                'direction' => 'in',
                'amount' => round($openingAmount, 2),
                'user_id' => $userId,
            ]
        );
    }
}
