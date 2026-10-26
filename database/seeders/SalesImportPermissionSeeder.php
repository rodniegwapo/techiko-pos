<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * The backdated sales import's permissions (template, check, import), for an existing database:
 * php artisan db:seed --class=SalesImportPermissionSeeder --force
 *
 * Creates any missing permission and gives super admin, admin and manager the ones they lack, like
 * RolePermissionSeeder; it never removes anything. A test keeps ROUTES in step with the sales-import routes.
 */
class SalesImportPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        'sales-import.index',
        'sales-import.template',
        'sales-import.preview',
        'sales-import.store',
    ];

    public const ROLES = ['super admin', 'admin', 'manager'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Sales import', ['sales-import'], self::ROUTES, self::ROLES);
    }
}
