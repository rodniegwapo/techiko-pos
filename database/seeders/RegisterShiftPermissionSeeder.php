<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Each cashier's own shift, and Counted money, for an existing database:
 * php artisan db:seed --class=RegisterShiftPermissionSeeder --force
 *
 * Everyone on the till starts, ends and reopens their own shift (no manager's PIN; a count that
 * is off waits for review). Supervisors and up see Counted money, approve counts, and see who
 * holds what. Creates the permissions if missing and gives each default role the ones it lacks,
 * like RolePermissionSeeder; it never removes anything.
 */
class RegisterShiftPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const TILL_ROUTES = ['register-shifts.start', 'register-shifts.end', 'register-shifts.reopen'];

    public const REVIEW_ROUTES = ['register-shifts.index', 'register-shifts.approve', 'register-shifts.money-by-user'];

    public const TILL_ROLES = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];

    public const REVIEW_ROLES = ['super admin', 'admin', 'manager', 'supervisor'];

    /** Today's drawer, where a shift is started and ended: supervisors didn't have it before. */
    public const DRAWER_ROUTES = ['wallet.money-movement'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Cashier shifts', ['register-shifts'], self::TILL_ROUTES, self::TILL_ROLES);
        $this->seedFeaturePermissions('Counted money', ['register-shifts'], self::REVIEW_ROUTES, self::REVIEW_ROLES);
        $this->seedFeaturePermissions('Today\'s drawer', [], self::DRAWER_ROUTES, ['supervisor']);
    }
}
