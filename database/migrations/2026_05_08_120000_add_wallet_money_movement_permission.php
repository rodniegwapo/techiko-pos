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
     * Grants wallet.money-movement to every role that already has wallet-cash-ledger.index.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'wallet')->value('id');

        $moneyMovement = Permission::query()->firstOrCreate(
            [
                'route_name' => 'wallet.money-movement',
                'guard_name' => 'web',
            ],
            [
                'name' => 'View money movement',
                'action' => 'money-movement',
                'module_id' => $moduleId,
            ]
        );

        $ledgerIndex = Permission::query()->where('route_name', 'wallet-cash-ledger.index')->where('guard_name', 'web')->first();

        if (! $ledgerIndex) {
            return;
        }

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $ledgerIndex->id)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            $role = Role::query()->find($roleId);
            if ($role && ! $role->hasPermissionTo($moneyMovement)) {
                $role->givePermissionTo($moneyMovement);
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

        Permission::query()->where('route_name', 'wallet.money-movement')->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
