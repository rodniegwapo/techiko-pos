<?php

namespace App\Http\Middleware;

use App\Helpers;
use App\Support\LocationAreas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a page out of a location that doesn't show it (LocationAreas): opening Finance at a
 * store, or Customers at a warehouse, goes to the location's home page instead. Only page visits
 * are turned away; data requests and saves made from other screens still work.
 */
class EnsureLocationArea
{
    public const MESSAGES = [
        'finance' => 'Finance is kept at the warehouse. Switch to the warehouse to open it.',
        'selling_floor' => "Customers, loyalty and voids are for stores. Switch to a store to open them.",
        'payment_channels' => 'Card terminals and e-wallets belong to a store. Switch to a store to open them.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $domain = $request->route('domain');
        $area = LocationAreas::areaOf($request->route()?->getName());

        $isPageVisit = $request->isMethod('GET') && (! $request->expectsJson() || $request->header('X-Inertia'));
        if (! $user || ! $domain || ! $area || ! $isPageVisit) {
            return $next($request);
        }

        // Same location the page shows: a restricted user's own, else the one asked for or picked.
        $locationId = $user->hasLocationRestriction()
            ? $user->getEffectiveLocationId($request->input('location_id'))
            : $request->input('location_id');
        $location = Helpers::getActiveLocation($domain, $locationId);

        if (! in_array($area, LocationAreas::hiddenAt($location, $user), true)) {
            return $next($request);
        }

        return redirect()
            ->route(Helpers::homeRouteFor($user, $location), array_filter([
                'domain' => $user->domain ?? (is_string($domain) ? $domain : $domain->name_slug),
                'location_id' => $request->input('location_id'),
            ]))
            ->with('notice', self::MESSAGES[$area] ?? "This page isn't available at this location.");
    }
}
