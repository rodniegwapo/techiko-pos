<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of the product cost when the line was sold, so profit on past sales
     * doesn't move when a product's cost is edited later. Null means the cost was unknown.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 4)->nullable()->after('unit_price');
        });

        // Best-effort backfill from today's product cost (voided lines included).
        DB::table('sale_items')
            ->whereNull('unit_cost')
            ->update([
                'unit_cost' => DB::raw('(select cost from products where products.id = sale_items.product_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
