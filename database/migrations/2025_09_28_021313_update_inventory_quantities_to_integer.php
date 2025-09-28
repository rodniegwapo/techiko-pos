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
        // Update product_inventory table quantities to integers
        Schema::table('product_inventory', function (Blueprint $table) {
            $table->integer('quantity_on_hand')->default(0)->change();
            $table->integer('quantity_reserved')->default(0)->change();
            $table->integer('quantity_available')->default(0)->change();
        });

        // Update inventory_movements table quantities to integers
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('quantity_before')->change();
            $table->integer('quantity_change')->change();
            $table->integer('quantity_after')->change();
        });

        // Update stock_adjustment_items table quantities to integers
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->integer('system_quantity')->change();
            $table->integer('actual_quantity')->change();
            $table->integer('adjustment_quantity')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert product_inventory table quantities back to decimals
        Schema::table('product_inventory', function (Blueprint $table) {
            $table->decimal('quantity_on_hand', 12, 3)->default(0)->change();
            $table->decimal('quantity_reserved', 12, 3)->default(0)->change();
            $table->decimal('quantity_available', 12, 3)->default(0)->change();
        });

        // Revert inventory_movements table quantities back to decimals
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('quantity_before', 12, 3)->change();
            $table->decimal('quantity_change', 12, 3)->change();
            $table->decimal('quantity_after', 12, 3)->change();
        });

        // Revert stock_adjustment_items table quantities back to decimals
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->decimal('system_quantity', 12, 3)->change();
            $table->decimal('actual_quantity', 12, 3)->change();
            $table->decimal('adjustment_quantity', 12, 3)->change();
        });
    }
};