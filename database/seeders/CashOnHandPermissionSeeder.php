<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Cash on hand (the business's cash the owner holds, and collecting approved shifts into it), for
 * an existing database:
 * php artisan db:seed --class=CashOnHandPermissionSeeder --force
 *
 * It moves money out of the stores' drawers, so only owners and admins get it. Creates the
 * permissions if missing and gives those roles the ones they lack, like RolePermissionSeeder;
 * it never removes anything. A test keeps ROUTES in step with the cash-on-hand routes.
 */
class CashOnHandPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        'cash-on-hand.index',
        'cash-on-hand.store',
        'cash-on-hand.collect',
    ];

    public const ROLES = ['super admin', 'admin'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Cash on hand', ['cash-on-hand'], self::ROUTES, self::ROLES);
    }
}
