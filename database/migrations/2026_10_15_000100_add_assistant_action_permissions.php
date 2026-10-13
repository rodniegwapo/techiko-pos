<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'assistant.actions.confirm' => 'Confirm changes prepared by the AI assistant',
        'assistant.actions.cancel' => 'Cancel changes prepared by the AI assistant',
    ];

    /**
     * Run the migrations.
     * Confirming goes to every role that may ask the assistant: each change still needs its own
     * permission (products.store, settings.update…), checked when it is confirmed.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'assistant')->value('id');

        $roleIds = collect();
        $source = Permission::query()->where('route_name', 'assistant.ask')->where('guard_name', 'web')->first();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()->whereIn('route_name', array_keys(self::PERMISSIONS))->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
