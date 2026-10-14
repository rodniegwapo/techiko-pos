<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may see costs, expenses and profit, and for which stores.
 * Goes by the assigned role's level, not users.role_level: that column defaults to 3 (manager)
 * for staff created in the app, so it can't tell a cashier apart.
 */
class FinanceAccess
{
    /** Admins and managers. */
    public static function canManage(?User $user): bool
    {
        $level = self::level($user);

        return $level !== null && $level <= 3;
    }

    /** Admins work across every store and business-wide; managers only their own store. */
    public static function canSeeAllStores(?User $user): bool
    {
        $level = self::level($user);

        return $level !== null && $level <= 2;
    }

    /** The store a manager is limited to, or null when the user may pick any store. */
    public static function restrictedLocationId(User $user): ?int
    {
        return self::canSeeAllStores($user) ? null : ($user->location_id ? (int) $user->location_id : null);
    }

    private static function level(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }
        if ($user->is_super_user) {
            return 1;
        }

        $roleLevel = $user->roles->min('level');

        return $roleLevel !== null ? (int) $roleLevel : (int) $user->role_level;
    }
}
