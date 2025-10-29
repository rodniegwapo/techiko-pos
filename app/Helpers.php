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
     * Get the active location for a user based on their role and domain
     */
    public static function getActiveLocation($domain = null, $locationId = null)
    {
        $user = auth()->user();
        
        // Handle case where domain might be a string or object
        $domainSlug = $domain instanceof \App\Models\Domain ? $domain->name_slug : $domain;
        
        // If a specific location_id is provided, use it
        if ($locationId) {
            return InventoryLocation::forDomain($domainSlug)->find($locationId);
        }
        
        // If user has a specific location_id, use it
        if ($user && $user->location_id) {
            return InventoryLocation::forDomain($domainSlug)->find($user->location_id);
        }
        
        // Fallback to domain's default location
        return InventoryLocation::active()
            ->forDomain($domainSlug)
            ->where('is_default', true)
            ->first();
    }
}
