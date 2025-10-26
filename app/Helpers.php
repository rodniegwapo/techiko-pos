<?php

namespace App;

use Carbon\Carbon;
use App\Models\InventoryLocation;

class Helpers
{
    public static function getDateRange($startDate = null, $endDate = null)
    {
        $range = [
            Carbon::parse($startDate)
                ->startOfDay()
                ->toDateTimeString(),
            Carbon::parse($endDate)
                ->endOfDay()
                ->toDateTimeString(),
        ];

        return $range;
    }

    /**
     * Get the effective location for a user based on their role and domain
     */
    public static function getEffectiveLocation($domain = null, $requestLocationId = null)
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        // If user has a specific location_id, use it
        if ($user->location_id) {
            return InventoryLocation::forDomain($domain->name_slug)->find($user->location_id);
        }

        // Fallback to domain's default location
        return InventoryLocation::active()
            ->forDomain($domain->name_slug)
            ->where('is_default', true)
            ->first();
    }
}
