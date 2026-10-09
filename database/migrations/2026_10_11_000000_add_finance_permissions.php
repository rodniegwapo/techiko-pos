<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'finance.dashboard' => 'View finance dashboard',
        'finance.income-statement' => 'View income statement',
        'finance.receivables' => 'View customer credit summary',
        'finance.explain' => 'Ask the AI to explain finances',
    ];

    /**
     * Run the migrations.
     * The Finance pages show costs and profit, so they go to the roles that can already see the
     * VAT report (owners/admins and managers), not to cashiers.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::table('permission_modules')->updateOrInsert(
            ['name' => 'finance'],
            [
                'display_name' => 'Finance',
                'icon' => 'report-analytics',
                'description' => 'Financial dashboard, income statement, customer credit and AI explanations',
                'sort_order' => 18,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $moduleId = DB::table('permission_modules')->where('name', 'finance')->value('id');

        $roleIds = collect();
        $source = Permission::query()->where('route_name', 'vat-report.index')->where('guard_name', 'web')->first();
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
        DB::table('permission_modules')->where('name', 'finance')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
