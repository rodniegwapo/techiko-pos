<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrate existing product-location relationships from products.location_id to location_product table
        $products = DB::table('products')
            ->whereNotNull('location_id')
            ->select('id as product_id', 'location_id')
            ->get();

        foreach ($products as $product) {
            DB::table('location_product')->insert([
                'product_id' => $product->product_id,
                'location_id' => $product->location_id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Clear the pivot table data
        DB::table('location_product')->truncate();
    }
};
