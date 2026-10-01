<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Indexes for the Sales History list, plus its permissions:
     * index/show go to every role that can take payments, export to roles that can export the VAT report.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['domain', 'transaction_date'], 'sales_domain_transaction_date_index');
            $table->index('payment_status', 'sales_payment_status_index');
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::table('permission_modules')->updateOrInsert(
            ['name' => 'sales-history'],
            [
                'display_name' => 'Sales History',
                'icon' => 'history',
                'description' => 'Browse past sales, reprint receipts and export',
                'sort_order' => 18,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $moduleId = DB::table('permission_modules')->where('name', 'sales-history')->value('id');

        $grants = [
            'sales.payment.store' => [
                'sales-history.index' => 'View sales history',
                'sales-history.show' => 'View sale details',
            ],
            'vat-report.export' => [
                'sales-history.export' => 'Export sales history',
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()
            ->whereIn('route_name', ['sales-history.index', 'sales-history.show', 'sales-history.export'])
            ->where('guard_name', 'web')
            ->delete();
        DB::table('permission_modules')->where('name', 'sales-history')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_domain_transaction_date_index');
            $table->dropIndex('sales_payment_status_index');
        });
    }
};
