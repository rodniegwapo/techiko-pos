<?php

namespace App\Http\Middleware;

use App\Helpers;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the sales screens and cart API out of warehouses: a warehouse holds and moves stock
 * but doesn't sell, so its users are sent to their home page (Inventory) instead.
 */
class EnsureSellableLocation
{
    public const MESSAGE = "Selling isn't available at a warehouse. Switch to a store to sell.";

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $domain = $request->route('domain');

        if (! $user || ! $domain) {
            return $next($request);
        }

        // Same store the page shows: a restricted user's own store, else the one asked for or picked.
        $locationId = $user->hasLocationRestriction()
            ? $user->getEffectiveLocationId($request->input('location_id'))
            : $request->input('location_id');
        $location = Helpers::getActiveLocation($domain, $locationId);

        if (! $location || ! $location->isWarehouse()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        // Stay in the warehouse that was asked for, rather than falling back to the default store.
        return redirect()
            ->route(Helpers::homeRouteFor($user, $location), array_filter([
                'domain' => $user->domain ?? $domain,
                'location_id' => $request->input('location_id'),
            ]))
            ->with('notice', self::MESSAGE);
    }
}
