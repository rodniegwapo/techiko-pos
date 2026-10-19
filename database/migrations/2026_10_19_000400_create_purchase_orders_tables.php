<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Purchasing: an order to a supplier for a store or warehouse, received in one or more deliveries.
 * Each delivery brings the stock in and puts its value on the order's supplier bill.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'purchase-orders.index' => 'View purchase orders',
        'purchase-orders.store' => 'Create purchase orders',
        'purchase-orders.update' => 'Edit purchase orders',
        'purchase-orders.order' => 'Send purchase orders',
        'purchase-orders.receive' => 'Receive purchase orders',
        'purchase-orders.cancel' => 'Cancel purchase orders',
    ];

    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('po_number');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->enum('status', ['draft', 'ordered', 'partial', 'received', 'cancelled'])->default('draft');
            $table->date('expected_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'po_number']);
            $table->index(['domain', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_ordered', 12, 2);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 4);
            $table->timestamps();
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')
                ->constrained('purchase_orders')->nullOnDelete();
        });

        $this->grantPermissions();
    }

    /** Admins and managers (role level 3 or lower), like Expenses. */
    private function grantPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::table('permission_modules')->updateOrInsert(
            ['name' => 'purchase-orders'],
            [
                'display_name' => 'Purchasing',
                'icon' => 'truck',
                'description' => 'Order stock from suppliers and receive it',
                'sort_order' => 19,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $moduleId = DB::table('permission_modules')->where('name', 'purchase-orders')->value('id');
        $roles = Role::query()->where('guard_name', 'web')->where('level', '<=', 3)->get();

        foreach (self::PERMISSIONS as $routeName => $name) {
            $permission = Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                ['name' => $name, 'action' => substr($routeName, strrpos($routeName, '.') + 1), 'module_id' => $moduleId]
            );
            foreach ($roles as $role) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::query()->whereIn('route_name', array_keys(self::PERMISSIONS))->where('guard_name', 'web')->delete();
        DB::table('permission_modules')->where('name', 'purchase-orders')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
