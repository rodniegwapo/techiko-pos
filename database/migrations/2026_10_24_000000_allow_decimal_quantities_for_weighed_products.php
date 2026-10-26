<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selling by weight and volume: 1.5 kg of rice, 0.75 L of oil. Quantities go back to
 * decimal(12,3) (the reverse of 2025_09_28_021313_update_inventory_quantities_to_integer), and a
 * sold type says whether it may be split: Kilo and Liter may, Piece, Box, Pack and Dozen may not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_inventory', function (Blueprint $table) {
            $table->decimal('quantity_on_hand', 12, 3)->default(0)->change();
            $table->decimal('quantity_reserved', 12, 3)->default(0)->change();
            $table->decimal('quantity_available', 12, 3)->default(0)->change();
            $table->decimal('location_reorder_level', 12, 3)->nullable()->change();
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('quantity_before', 12, 3)->change();
            $table->decimal('quantity_change', 12, 3)->change();
            $table->decimal('quantity_after', 12, 3)->change();
        });
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->decimal('system_quantity', 12, 3)->change();
            $table->decimal('actual_quantity', 12, 3)->change();
            $table->decimal('adjustment_quantity', 12, 3)->change();
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->default(1)->change();
        });
        Schema::table('inventory_transfer_recommendations', function (Blueprint $table) {
            $table->decimal('recommended_quantity', 12, 3)->change();
        });

        Schema::table('product_sold_types', function (Blueprint $table) {
            $table->string('unit', 10)->default('pc')->after('name');
            $table->boolean('allows_decimal')->default(false)->after('unit');
        });
        $units = ['Piece' => 'pc', 'Box' => 'box', 'Pack' => 'pack', 'Dozen' => 'doz'];
        foreach ($units as $name => $unit) {
            DB::table('product_sold_types')->where('name', $name)->update(['unit' => $unit]);
        }
        foreach (['Kilo' => 'kg', 'Liter' => 'L'] as $name => $unit) {
            DB::table('product_sold_types')->updateOrInsert(
                ['name' => $name],
                ['unit' => $unit, 'allows_decimal' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('product_sold_types')->whereIn('name', ['Kilo', 'Liter'])->delete();
        Schema::table('product_sold_types', function (Blueprint $table) {
            $table->dropColumn(['unit', 'allows_decimal']);
        });

        Schema::table('product_inventory', function (Blueprint $table) {
            $table->integer('quantity_on_hand')->default(0)->change();
            $table->integer('quantity_reserved')->default(0)->change();
            $table->integer('quantity_available')->default(0)->change();
            $table->integer('location_reorder_level')->nullable()->change();
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('quantity_before')->change();
            $table->integer('quantity_change')->change();
            $table->integer('quantity_after')->change();
        });
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->integer('system_quantity')->change();
            $table->integer('actual_quantity')->change();
            $table->integer('adjustment_quantity')->change();
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });
        Schema::table('inventory_transfer_recommendations', function (Blueprint $table) {
            $table->integer('recommended_quantity')->change();
        });
    }
};
