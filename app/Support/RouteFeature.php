<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Facades\Route;

/**
 * Whether an organization has the features a route needs: the route's `domain.feature:<x>`
 * middleware (Finance, AI…). Used where code runs a route's work without going through its
 * middleware, like the AI assistant's confirmed changes.
 */
class RouteFeature
{
    /** @param  string  $routeName  With or without the "domains." prefix. */
    public static function allows(Domain $domain, string $routeName): bool
    {
        $route = Route::getRoutes()->getByName(str_starts_with($routeName, 'domains.') ? $routeName : 'domains.'.$routeName)
            ?? Route::getRoutes()->getByName($routeName);
        if (! $route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'domain.feature:')
                && ! $domain->hasFeature(substr($middleware, strlen('domain.feature:')))) {
                return false;
            }
        }

        return true;
    }
}
