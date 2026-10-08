<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissions for product modifiers (add-ons), in the Products module: viewing them goes to every
     * role that can view products, changing them to every role that can change products.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'products')->value('id');

        $grants = [
            'products.index' => [
                'products.modifier-groups.index' => 'View product modifiers',
            ],
            'products.update' => [
                'products.modifier-groups.store' => 'Create product modifiers',
                'products.modifier-groups.update' => 'Edit product modifiers',
                'products.modifier-groups.destroy' => 'Delete product modifiers',
            ],
        ];

        foreach ($grants as $sourceRoute => $newPermissions) {
            $roleIds = collect();
            $source = Permission::query()->where('route_name', $sourceRoute)->where('guard_name', 'web')->first();
            if ($source) {
                $roleIds = DB::table('role_has_permissions')->where('permission_id', $source->id)->pluck('role_id');
            }

            foreach ($newPermissions as $routeName => $name) {
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
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()
            ->where('route_name', 'like', 'products.modifier-groups.%')
            ->where('guard_name', 'web')
            ->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
