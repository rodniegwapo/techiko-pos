<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cashiers count the cash and end their own shift on Today's drawer. They see what should be in
 * the drawer, not whether their count came out short or over (that stays with managers).
 */
return new class extends Migration
{
    private const ROUTES = [
        'wallet.money-movement',
        'wallet-cash-ledger.counted-cash.store',
        'wallet-cash-ledger.end-shift',
    ];

    public function up(): void
    {
        $this->each(fn (Role $cashier, Permission $permission) => $cashier->hasPermissionTo($permission)
            ?: $cashier->givePermissionTo($permission));
    }

    public function down(): void
    {
        $this->each(fn (Role $cashier, Permission $permission) => $cashier->hasPermissionTo($permission)
            && $cashier->revokePermissionTo($permission));
    }

    private function each(callable $apply): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $cashier = Role::query()->where('name', 'cashier')->where('guard_name', 'web')->first();
        if ($cashier) {
            Permission::query()->whereIn('route_name', self::ROUTES)->where('guard_name', 'web')->get()
                ->each(fn (Permission $permission) => $apply($cashier, $permission));
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
