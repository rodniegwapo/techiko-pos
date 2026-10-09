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
    private const PERMISSIONS = [
        'finance.other-liabilities.store' => 'Record other liabilities',
        'finance.other-liabilities.update' => 'Edit other liabilities',
        'finance.other-liabilities.destroy' => 'Delete other liabilities',
    ];

    /**
     * Run the migrations.
     * - other_liabilities: what the business owes besides supplier bills and loans (taxes due,
     *   customer deposits, unpaid wages…).
     * - financial_snapshots: the balance sheet figures as of each day, so inventory, customer
     *   credit and cash can be compared over time (they are only known "as of today" otherwise).
     * - financial_accounts.payment_card_type_id: a bank or e-wallet account fed by a payment
     *   channel, so sales paid through it raise the account's balance on their own.
     */
    public function up(): void
    {
        Schema::create('other_liabilities', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('name');
            $table->enum('category', ['tax', 'customer_deposit', 'wages', 'other'])->default('other');
            $table->decimal('amount', 14, 2);
            $table->date('incurred_date');
            $table->date('due_date')->nullable();
            $table->date('settled_date')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->date('as_of_date');
            $table->decimal('cash_in_drawer', 14, 2)->default(0);
            $table->decimal('accounts_balance', 14, 2)->default(0);
            $table->decimal('inventory_value', 15, 2)->default(0);
            $table->decimal('receivables', 14, 2)->default(0);
            $table->decimal('receivables_overdue', 14, 2)->default(0);
            $table->decimal('fixed_assets', 14, 2)->default(0);
            $table->decimal('payables', 14, 2)->default(0);
            $table->decimal('loans', 14, 2)->default(0);
            $table->decimal('other_liabilities', 14, 2)->default(0);
            $table->decimal('total_assets', 15, 2)->default(0);
            $table->decimal('total_liabilities', 15, 2)->default(0);
            $table->decimal('net_worth', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['domain', 'as_of_date']);
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->foreignId('payment_card_type_id')->nullable()->after('account_number')
                ->constrained('payment_card_types')->nullOnDelete();
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $moduleId = DB::table('permission_modules')->where('name', 'finance')->value('id');
        $source = Permission::query()->where('route_name', 'finance.dashboard')->where('guard_name', 'web')->first();
        $roleIds = $source ? DB::table('role_has_permissions')->where('permission_id', $source->id)->pluck('role_id') : collect();

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

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_card_type_id');
        });
        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('other_liabilities');
    }
};
