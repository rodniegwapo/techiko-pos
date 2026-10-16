<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'ai-usage.index' => 'View AI usage',
    ];

    /**
     * The AI usage page goes with the plan, so every role that can open Servicing payment
     * (billing) may see it.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'assistant')->value('id');

        $roleIds = collect();
        $source = Permission::query()->where('route_name', 'billing.servicing.index')->where('guard_name', 'web')->first();
        if ($source) {
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $source->id)->pluck('role_id');
        }

        foreach (self::PERMISSIONS as $routeName => $name) {
            $permission = Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                ['name' => $name, 'action' => substr($routeName, strrpos($routeName, '.') + 1), 'module_id' => $moduleId],
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

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()->whereIn('route_name', array_keys(self::PERMISSIONS))->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
