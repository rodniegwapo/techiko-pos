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
     * Voiding a whole completed sale from Sales History: a "voided" status, who voided it and why,
     * and the sales-history.void permission for every role that can already void cart items.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY payment_status ENUM('paid', 'pending', 'refunded', 'partial', 'voided') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('payment_status');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->foreignId('void_approved_by')->nullable()->after('voided_by')->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable()->after('void_approved_by');
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $moduleId = DB::table('permission_modules')->where('name', 'sales-history')->value('id');
        $permission = Permission::query()->firstOrCreate(
            [
                'route_name' => 'sales-history.void',
                'guard_name' => 'web',
            ],
            [
                'name' => 'Void a completed sale',
                'action' => 'void',
                'module_id' => $moduleId,
            ]
        );

        $itemVoid = Permission::query()->where('route_name', 'sales.items.void')->where('guard_name', 'web')->first();
        if ($itemVoid) {
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $itemVoid->id)->pluck('role_id');
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

        Permission::query()->where('route_name', 'sales-history.void')->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_approved_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE sales SET payment_status = 'paid' WHERE payment_status = 'voided'");
            DB::statement("ALTER TABLE sales MODIFY payment_status ENUM('paid', 'pending', 'refunded', 'partial') NOT NULL DEFAULT 'pending'");
        }
    }
};
