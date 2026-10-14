<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'finance.cash-flow' => 'View cash flow statement',
        'finance.balance-sheet' => 'View balance sheet',
        'finance.other-income.index' => 'View other income',
        'finance.other-income.store' => 'Record other income',
        'finance.other-income.update' => 'Edit other income',
        'finance.other-income.destroy' => 'Delete other income',
        'finance.payables.index' => 'View supplier bills',
        'finance.suppliers.store' => 'Add suppliers',
        'finance.suppliers.update' => 'Edit suppliers',
        'finance.bills.store' => 'Record supplier bills',
        'finance.bills.update' => 'Edit supplier bills',
        'finance.bills.destroy' => 'Delete supplier bills',
        'finance.bill-payments.store' => 'Record supplier payments',
        'finance.bill-payments.destroy' => 'Delete supplier payments',
    ];

    /**
     * Run the migrations.
     * Cash flow, balance sheet, other income and supplier bills go to the roles that already have
     * the Finance dashboard (owners/admins and managers). Expenses have their own permissions.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'finance')->value('id');

        $roleIds = collect();
        $source = Permission::query()->where('route_name', 'finance.dashboard')->where('guard_name', 'web')->first();
        if ($source) {
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $source->id)->pluck('role_id');
        }

        foreach (self::PERMISSIONS as $routeName => $name) {
            $permission = Permission::query()->firstOrCreate(
                [
                    'route_name' => $routeName,
                    'guard_name' => 'web',
                ],
                [
                    'name' => $name,
                    'action' => substr($routeName, strrpos($routeName, '.') + 1),
                    'module_id' => $moduleId,
                ]
            );

            foreach ($roleIds as $roleId) {
                $role = Role::query()->find($roleId);
                if ($role && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()
            ->whereIn('route_name', array_keys(self::PERMISSIONS))
            ->where('guard_name', 'web')
            ->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
