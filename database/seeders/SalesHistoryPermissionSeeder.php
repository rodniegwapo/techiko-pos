<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Sales History's permissions (list, details, void, CSV export), for an existing database:
 * php artisan db:seed --class=SalesHistoryPermissionSeeder --force
 *
 * Creates any missing permission and gives each default role the ones it lacks, like RolePermissionSeeder:
 * everyone who takes payments can list, open and void (staff below manager only see their own sales, and a
 * void still needs a manager's approval); the CSV export is for managers and above. It never removes anything.
 * A test keeps ROUTES in step with the Sales History routes.
 */
class SalesHistoryPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        'sales-history.index',
        'sales-history.show',
        'sales-history.void',
        'sales-history.export',
    ];

    public const EVERYONE_ROUTES = ['sales-history.index', 'sales-history.show', 'sales-history.void'];

    public const EVERYONE_ROLES = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];

    public const EXPORT_ROUTES = ['sales-history.export'];

    public const EXPORT_ROLES = ['super admin', 'admin', 'manager'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Sales History', ['sales-history'], self::EVERYONE_ROUTES, self::EVERYONE_ROLES);
        $this->seedFeaturePermissions('Sales History export', ['sales-history'], self::EXPORT_ROUTES, self::EXPORT_ROLES);
    }
}
