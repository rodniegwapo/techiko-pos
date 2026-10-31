<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Spot check my drawer: count your own tray during your shift against that shift's Expected.
 * Everyone on the till, owners and admins too; only ever your own (the service checks that).
 * For an existing database:
 * php artisan db:seed --class=ShiftSpotCheckPermissionSeeder --force
 *
 * Creates the permission if it is missing and gives each default role the ones it lacks, like
 * RolePermissionSeeder; it never removes anything.
 */
class ShiftSpotCheckPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = ['register-shifts.spot-check'];

    public const ROLES = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Shift spot check', ['register-shifts'], self::ROUTES, self::ROLES);
    }
}
