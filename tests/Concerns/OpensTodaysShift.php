<?php

namespace Tests\Concerns;

use App\Models\InventoryLocation;
use App\Models\RegisterShift;
use App\Models\User;
use App\Models\WalletCashReconciliation;

/**
 * Taking payment needs the cashier's own shift open at the store, and the store's day not ended
 * (WalletShiftGuard). Tests about checkout, voids and payment channels aren't about shifts, so
 * every store they create opens its day, and every user gets a shift open at every store of their
 * organization, whichever of the two is created first.
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
            User::query()->where('domain', $location->domain)->get()
                ->each(fn (User $user) => self::openShiftFor($location, $user));
        });

        User::created(function (User $user) {
            if (! $user->domain) {
                return;
            }
            InventoryLocation::query()->where('domain', $user->domain)->get()
                ->reject(fn (InventoryLocation $location) => $location->isWarehouse())
                ->each(fn (InventoryLocation $location) => self::openShiftFor($location, $user));
        });
    }

    private static function openShiftFor(InventoryLocation $location, User $user): void
    {
        RegisterShift::query()->firstOrCreate(
            ['domain' => $location->domain, 'location_id' => $location->id, 'user_id' => $user->id, 'status' => RegisterShift::OPEN],
            ['business_date' => today()->toDateString(), 'opening_cash' => 0, 'opened_at' => now()],
        );
    }
}
