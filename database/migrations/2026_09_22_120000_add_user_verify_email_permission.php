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
     * Grants users.verify-email to every role that can already switch a user on or off
     * (users.toggle-status), since both are ways of letting somebody into the app.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'users')->value('id');

        $verifyEmail = Permission::query()->firstOrCreate(
            [
                'route_name' => 'users.verify-email',
                'guard_name' => 'web',
            ],
            [
                'name' => 'Verify email',
                'action' => 'verify-email',
                'module_id' => $moduleId,
            ]
        );

        $toggleStatus = Permission::query()->where('route_name', 'users.toggle-status')->where('guard_name', 'web')->first();

        if (! $toggleStatus) {
            return;
        }

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $toggleStatus->id)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            $role = Role::query()->find($roleId);
            if ($role && ! $role->hasPermissionTo($verifyEmail)) {
                $role->givePermissionTo($verifyEmail);
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

        Permission::query()->where('route_name', 'users.verify-email')->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
