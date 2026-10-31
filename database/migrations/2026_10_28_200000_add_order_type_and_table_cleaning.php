<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dine-in that guides the cashier:
 * - sales.order_type: dine_in or take_out, set when a sale at a dine-in store is paid (null elsewhere);
 * - dining_tables.needs_cleaning_at: the table's bill was paid and staff haven't marked it ready yet;
 * - inventory_locations.table_cleaning: whether this store asks for that step (on by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('order_type', 20)->nullable()->after('is_tab');
        });
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->timestamp('needs_cleaning_at')->nullable()->after('is_active');
        });
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->boolean('table_cleaning')->default(true)->after('dine_in');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('order_type'));
        Schema::table('dining_tables', fn (Blueprint $table) => $table->dropColumn('needs_cleaning_at'));
        Schema::table('inventory_locations', fn (Blueprint $table) => $table->dropColumn('table_cleaning'));
    }
};
