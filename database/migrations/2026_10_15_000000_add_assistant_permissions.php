<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'assistant.ask' => 'Ask the AI assistant',
    ];

    /**
     * Run the migrations.
     * The AI assistant answers within each user's own permissions, so every role that can open the
     * dashboard may ask it.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::table('permission_modules')->updateOrInsert(['name' => 'assistant'], [
            'display_name' => 'AI Assistant',
            'icon' => 'robot',
            'description' => 'The AI assistant in the chat drawer: how-to help and actions done for you',
            'sort_order' => 21,
            'is_active' => true,
        ]);
        $moduleId = DB::table('permission_modules')->where('name', 'assistant')->value('id');

        $roleIds = collect();
        $source = Permission::query()->where('route_name', 'dashboard')->where('guard_name', 'web')->first();
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
        DB::table('permission_modules')->where('name', 'assistant')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
