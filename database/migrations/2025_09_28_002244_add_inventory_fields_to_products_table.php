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
        Schema::table('products', function (Blueprint $table) {
            // Basic inventory settings
            $table->boolean('track_inventory')->default(true)->after('category_id');
            $table->decimal('reorder_level', 10, 2)->default(0)->after('track_inventory');
            $table->decimal('max_stock_level', 10, 2)->nullable()->after('reorder_level');
            $table->decimal('unit_weight', 8, 3)->nullable()->after('max_stock_level');
            $table->string('unit_of_measure', 20)->default('piece')->after('unit_weight');
            
            // Stock status enum
            $table->enum('stock_status', ['in_stock', 'low_stock', 'out_of_stock', 'discontinued'])
                  ->default('in_stock')->after('unit_of_measure');
            
            // Supplier information
            $table->string('supplier_sku')->nullable()->after('stock_status');
            $table->text('notes')->nullable()->after('supplier_sku');
            
            // Indexes for performance
            $table->index('stock_status');
            $table->index('track_inventory');
            $table->index(['stock_status', 'track_inventory']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['products_stock_status_index']);
            $table->dropIndex(['products_track_inventory_index']);
            $table->dropIndex(['products_stock_status_track_inventory_index']);
            
            $table->dropColumn([
                'track_inventory',
                'reorder_level',
                'max_stock_level',
                'unit_weight',
                'unit_of_measure',
                'stock_status',
                'supplier_sku',
                'notes'
            ]);
        });
    }
};