<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Lets everyone on the till reopen a closed shift (several drawers share a store; every reopen is
 * logged with who and when), for an existing database:
 * php artisan db:seed --class=ShiftReopenPermissionSeeder --force
 *
 * Creates the permission if it is missing and gives each default role the ones it lacks, like
 * RolePermissionSeeder; it never removes anything. A role the owner took it from on the Roles page
 * gets it back when this is run again.
 */
class ShiftReopenPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = ['wallet-cash-ledger.reopen-shift'];

    public const ROLES = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Shift reopen', [], self::ROUTES, self::ROLES);
    }
}
