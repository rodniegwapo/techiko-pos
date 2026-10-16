<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales now hard-block without an open shift, so the cashier who hits that block needs to be able
 * to open it themselves (declare opening cash) instead of waiting on a manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()->where('route_name', 'wallet-cash-ledger.opening-cash.store')->where('guard_name', 'web')->first();
        $cashier = Role::query()->where('name', 'cashier')->where('guard_name', 'web')->first();

        if ($permission && $cashier && ! $cashier->hasPermissionTo($permission)) {
            $cashier->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()->where('route_name', 'wallet-cash-ledger.opening-cash.store')->where('guard_name', 'web')->first();
        $cashier = Role::query()->where('name', 'cashier')->where('guard_name', 'web')->first();

        if ($permission && $cashier && $cashier->hasPermissionTo($permission)) {
            $cashier->revokePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
