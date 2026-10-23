<?php

namespace App\Support;

use App\Models\User;

/**
 * Whether a user may use a route, by the same rule as UserPermissionCheckMiddleware: super users
 * may use everything; everyone else needs a permission whose route_name matches the route name
 * without its "domains." prefix (domains.products.store → products.store), or one of the
 * permissions the route is an alias of (ALIASES).
 */
class RoutePermission
{
    /**
     * Pages that open with an existing permission instead of one of their own: any one listed is
     * enough. Shared with the frontend so the menu agrees.
     */
    public const ALIASES = [
        // The wallet pages, split out of the old Money movement page.
        'wallet.drawer' => ['wallet.money-movement'],
        // Past counts and their short/over: for those who keep the books, not the cashier.
        'wallet.history' => ['wallet-cash-ledger.index'],
        'wallet.cash-in-out' => ['wallet-cash-ledger.index'],
        'wallet.balances' => ['wallet-cash-ledger.index', 'payment-card-types.index'],
        'payment-card-types.details' => ['payment-card-types.money'],
        'billing.gcash.legacy' => ['billing.servicing.index'],
    ];

    /** @return list<string> Permission route names that open this route; any one is enough. */
    public static function permissionsFor(string $routeName): array
    {
        $routeName = str_starts_with($routeName, 'domains.') ? substr($routeName, strlen('domains.')) : $routeName;

        return self::ALIASES[$routeName] ?? [$routeName];
    }

    public static function allows(?User $user, string $routeName): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return true;
        }

        $permissions = $user->getAllPermissions();

        foreach (self::permissionsFor($routeName) as $permission) {
            if ($permissions->contains('route_name', $permission)) {
                return true;
            }
        }

        return false;
    }
}
