<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'finance.balance-items.index' => 'View accounts, loans and assets',
        'finance.accounts.store' => 'Add bank and e-wallet accounts',
        'finance.accounts.update' => 'Edit bank and e-wallet accounts',
        'finance.account-balances.store' => 'Record account balances',
        'finance.account-balances.destroy' => 'Delete account balances',
        'finance.loans.store' => 'Record loans',
        'finance.loans.update' => 'Edit loans',
        'finance.loans.destroy' => 'Delete loans',
        'finance.loan-payments.store' => 'Record loan repayments',
        'finance.loan-payments.destroy' => 'Delete loan repayments',
        'finance.assets.store' => 'Record equipment and other assets',
        'finance.assets.update' => 'Edit equipment and other assets',
        'finance.assets.destroy' => 'Delete equipment and other assets',
        'finance.owner-investments.store' => 'Record owner investments',
        'finance.owner-investments.update' => 'Edit owner investments',
        'finance.owner-investments.destroy' => 'Delete owner investments',
        'finance.ask' => 'Ask the AI about the business',
        'finance.reviews.index' => 'View monthly business reviews',
        'finance.reviews.generate' => 'Generate monthly business reviews',
    ];

    /**
     * Run the migrations.
     * Accounts, loans, assets, owner investments, AI questions and monthly reviews go to the roles
     * that already have the Finance dashboard (owners/admins and managers).
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'finance')->value('id');

        $roleIds = collect();
        $source = Permission::query()->where('route_name', 'finance.dashboard')->where('guard_name', 'web')->first();
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
