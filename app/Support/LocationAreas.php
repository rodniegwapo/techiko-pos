<?php

namespace App\Support;

use App\Models\InventoryLocation;
use App\Models\User;

/**
 * Which parts of the app a location shows (config/location_areas.php): a warehouse sees the whole
 * business but not the selling floor; a store everything but the books, when the organization
 * has a warehouse to keep them at.
 */
class LocationAreas
{
    /** @return list<string> Areas hidden at this location (for this user). */
    public static function hiddenAt(?InventoryLocation $location, ?User $user = null): array
    {
        if (! $location) {
            return [];
        }

        $type = $location->isWarehouse() ? 'warehouse' : 'store';
        $hidden = config("location_areas.hidden_at.{$type}", []);

        // The books stay at the warehouse for store staff; owners and admins see them at any store,
        // and so does everyone when there is no warehouse to keep them at.
        if ($type === 'store' && (FinanceAccess::canSeeAllStores($user) || ! self::hasWarehouse($location->domain))) {
            $hidden = array_values(array_diff($hidden, ['finance']));
        }

        return $hidden;
    }

    /** The area a route belongs to, or null when every location shows it. */
    public static function areaOf(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }
        foreach (config('location_areas.areas', []) as $area => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($routeName, $prefix)) {
                    return $area;
                }
            }
        }

        return null;
    }

    private static function hasWarehouse(?string $domain): bool
    {
        return InventoryLocation::query()->forDomain($domain)->active()
            ->where('type', 'warehouse')->exists();
    }
}
