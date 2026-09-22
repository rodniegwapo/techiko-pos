<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Grants inventory.locations.switch (the header's store switcher, per admin session) to every
     * role that could already change the default store, which is what the switcher used to do.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $setDefault = Permission::query()->where('route_name', 'inventory.locations.set-default')->where('guard_name', 'web')->first();

        $switch = Permission::query()->firstOrCreate(
            [
                'route_name' => 'inventory.locations.switch',
                'guard_name' => 'web',
            ],
            [
                'name' => 'Switch store',
                'action' => 'switch',
                'module_id' => $setDefault?->module_id,
            ]
        );

        if (! $setDefault) {
            return;
        }

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $setDefault->id)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            $role = Role::query()->find($roleId);
            if ($role && ! $role->hasPermissionTo($switch)) {
                $role->givePermissionTo($switch);
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

        Permission::query()->where('route_name', 'inventory.locations.switch')->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
