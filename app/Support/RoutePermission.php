<?php

namespace App\Support;

use App\Models\User;

/**
 * Whether a user may use a route, by the same rule as UserPermissionCheckMiddleware: super users
 * may use everything; everyone else needs a permission whose route_name matches the route name
 * without its "domains." prefix (domains.products.store → products.store).
 */
class RoutePermission
{
    public static function allows(?User $user, string $routeName): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return true;
        }

        $routeName = str_starts_with($routeName, 'domains.') ? substr($routeName, strlen('domains.')) : $routeName;

        return $user->getAllPermissions()->contains('route_name', $routeName);
    }
}
