<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'expenses.index' => 'View expenses',
        'expenses.store' => 'Record expenses',
        'expenses.update' => 'Edit expenses',
        'expenses.destroy' => 'Delete expenses',
        'expenses.export' => 'Export expenses',
        'expenses.receipt' => 'View expense receipts',
        'expenses.categories.store' => 'Add expense categories',
        'expenses.categories.update' => 'Edit expense categories',
        'expenses.categories.destroy' => 'Delete expense categories',
    ];

    /**
     * Expenses are management information: granted to admins and managers (role level 3 or lower),
     * the same group that sees all sales and profit.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::table('permission_modules')->updateOrInsert(
            ['name' => 'expenses'],
            [
                'display_name' => 'Expenses',
                'icon' => 'receipt-2',
                'description' => 'Record business expenses such as rent, salaries and utilities',
                'sort_order' => 18,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $moduleId = DB::table('permission_modules')->where('name', 'expenses')->value('id');

        $roles = Role::query()->where('guard_name', 'web')->where('level', '<=', 3)->get();

        foreach (self::PERMISSIONS as $routeName => $name) {
            $permission = Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                [
                    'name' => $name,
                    'action' => substr($routeName, strrpos($routeName, '.') + 1),
                    'module_id' => $moduleId,
                ]
            );

            foreach ($roles as $role) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()
            ->whereIn('route_name', array_keys(self::PERMISSIONS))
            ->where('guard_name', 'web')
            ->delete();
        DB::table('permission_modules')->where('name', 'expenses')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
