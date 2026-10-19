<?php

namespace Tests\Concerns;

use App\Models\InventoryLocation;
use App\Models\WalletCashReconciliation;

/**
 * Taking payment needs today's shift open at the store (WalletShiftGuard). Tests about checkout,
 * voids and payment channels aren't about shifts, so every store they create opens one for today.
 */
trait OpensTodaysShift
{
    protected function openTodaysShiftForNewStores(): void
    {
        InventoryLocation::created(function (InventoryLocation $location) {
            if ($location->isWarehouse()) {
                return;
            }

            WalletCashReconciliation::query()->firstOrCreate(
                ['domain' => $location->domain, 'location_id' => $location->id, 'business_date' => today()->toDateString()],
                ['opening_cash' => 0, 'is_closed' => false],
            );
        });
    }
}
