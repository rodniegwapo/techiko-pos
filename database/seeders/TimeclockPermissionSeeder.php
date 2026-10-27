<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * Timeclock's permissions (clock in/out on the POS, and the manager's timesheets), for an
 * existing database: php artisan db:seed --class=TimeclockPermissionSeeder --force
 *
 * Everyone on the till can clock in and out; admins and managers also keep the timesheets. It
 * creates any missing permission and gives the default roles the ones they lack; it never removes
 * anything. A test keeps the lists in step with the timesheet routes.
 */
class TimeclockPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        'timesheets.punch',
        'timesheets.index',
        'timesheets.export',
        'timesheets.store',
        'timesheets.update',
    ];

    /** Clocking in and out only. */
    public const PUNCH_ROUTES = ['timesheets.punch'];

    public const MANAGER_ROLES = ['super admin', 'admin', 'manager'];

    public const STAFF_ROLES = ['supervisor', 'cashier'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Timeclock', ['timesheets'], self::ROUTES, self::MANAGER_ROLES);
        $this->seedFeaturePermissions('Timeclock (till staff)', ['timesheets'], self::PUNCH_ROUTES, self::STAFF_ROLES);
    }
}
