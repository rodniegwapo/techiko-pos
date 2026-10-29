<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Dine-in's permissions (tabs on the POS, and the tables setup), for an existing database:
 * php artisan db:seed --class=DineInPermissionSeeder --force
 *
 * Everyone on the till runs tabs: open, pick up, move, merge, split, send to the kitchen and
 * cancel (a tab with items still needs a manager's PIN to cancel). Setting up a store's tables and
 * turning dine-in on is for admins and managers. It only adds; a test keeps the lists in step with
 * the routes.
 */
class DineInPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const TAB_ROUTES = [
        'tabs.index',
        'tabs.store',
        'tabs.leave',
        'tabs.update',
        'tabs.select',
        'tabs.move',
        'tabs.merge',
        'tabs.split',
        'tabs.cancel',
        'tabs.send',
        'tabs.tickets',
        'tabs.ready',
    ];

    public const TABLE_ROUTES = [
        'dining-tables.index',
        'dining-tables.store',
        'dining-tables.settings',
        'dining-tables.layout',
        'dining-tables.update',
        'dining-tables.destroy',
    ];

    public const MANAGER_ROLES = ['super admin', 'admin', 'manager'];

    public const STAFF_ROLES = ['supervisor', 'cashier'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Dine-in', ['tabs', 'dining-tables'], [...self::TAB_ROUTES, ...self::TABLE_ROUTES], self::MANAGER_ROLES);
        $this->seedFeaturePermissions('Dine-in (till staff)', ['tabs'], self::TAB_ROUTES, self::STAFF_ROLES);
    }
}
