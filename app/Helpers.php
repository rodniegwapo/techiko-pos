<?php

namespace App;

use Carbon\Carbon;
use App\Models\InventoryLocation;

class Helpers
{
    /**
     * The whole days between two dates, or null if either of them isn't a date.
     *
     * A date typed into the address bar by hand is not worth a server error, so an unreadable one
     * gives no range and the caller simply doesn't narrow by it.
     */
    public static function getDateRange($startDate = null, $endDate = null): ?array
    {
        try {
            return [
                Carbon::parse($startDate)
                    ->startOfDay()
                    ->toDateTimeString(),
                Carbon::parse($endDate)
                    ->endOfDay()
                    ->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Session key for the store an admin picked in the header's location badge, per organization.
     */
    public static function selectedLocationSessionKey(string $domainSlug): string
    {
        return "selected_location.{$domainSlug}";
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

        // The store this admin switched to (their own session only; the organization's default
        // store is left alone). Users restricted to a store don't get to pick one.
        if ($user && $domainSlug && ! $user->hasLocationRestriction() && request()->hasSession()) {
            $selectedId = request()->session()->get(self::selectedLocationSessionKey($domainSlug));
            $selected = $selectedId
                ? InventoryLocation::active()->forDomain($domainSlug)->find($selectedId)
                : null;
            if ($selected) {
                return $selected;
            }
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
