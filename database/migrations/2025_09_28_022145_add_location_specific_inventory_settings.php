<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add location-specific inventory settings to product_inventory table
        Schema::table('product_inventory', function (Blueprint $table) {
            $table->integer('location_reorder_level')->nullable()->after('quantity_available');
            $table->integer('location_max_stock')->nullable()->after('location_reorder_level');
            $table->decimal('location_markup_percentage', 5, 2)->nullable()->after('location_max_stock');
            $table->boolean('auto_reorder_enabled')->default(false)->after('location_markup_percentage');
            $table->json('demand_pattern')->nullable()->after('auto_reorder_enabled'); // Store sales velocity data
            
            // Add indexes for performance
            $table->index('location_reorder_level');
            $table->index('auto_reorder_enabled');
        });

        // Add performance tracking fields to inventory_locations table
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->decimal('total_inventory_value', 15, 4)->default(0)->after('notes');
            $table->integer('total_products_count')->default(0)->after('total_inventory_value');
            $table->integer('low_stock_products_count')->default(0)->after('total_products_count');
            $table->integer('out_of_stock_products_count')->default(0)->after('low_stock_products_count');
            $table->timestamp('last_inventory_update')->nullable()->after('out_of_stock_products_count');
            $table->json('performance_metrics')->nullable()->after('last_inventory_update'); // Store analytics data
            
            // Add indexes
            $table->index('total_inventory_value');
            $table->index('last_inventory_update');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inventory', function (Blueprint $table) {
            $table->dropIndex(['location_reorder_level']);
            $table->dropIndex(['auto_reorder_enabled']);
            $table->dropColumn([
                'location_reorder_level',
                'location_max_stock',
                'location_markup_percentage',
                'auto_reorder_enabled',
                'demand_pattern'
            ]);
        });

        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->dropIndex(['total_inventory_value']);
            $table->dropIndex(['last_inventory_update']);
            $table->dropColumn([
                'total_inventory_value',
                'total_products_count',
                'low_stock_products_count',
                'out_of_stock_products_count',
                'last_inventory_update',
                'performance_metrics'
            ]);
        });
    }
};