<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Purchasing's permissions (purchase orders: create, send, receive, cancel), for an existing
 * database: php artisan db:seed --class=PurchasingPermissionSeeder --force
 *
 * Creates any missing permission and gives super admin, admin and manager the ones they lack, like
 * RolePermissionSeeder; it never removes anything. A test keeps ROUTES in step with the Purchasing routes.
 */
class PurchasingPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        'purchase-orders.index',
        'purchase-orders.store',
        'purchase-orders.update',
        'purchase-orders.order',
        'purchase-orders.receive',
        'purchase-orders.cancel',
    ];

    public const ROLES = ['super admin', 'admin', 'manager'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Purchasing', ['purchase-orders'], self::ROUTES, self::ROLES);
    }
}
