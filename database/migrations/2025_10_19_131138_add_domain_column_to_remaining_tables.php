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
        // Tables that need domain column added
        $tables = [
            'sales',
            'products', 
            'customers',
            'users',
            'loyalty_tiers',
            'stock_adjustments',
            'inventory_locations',
            'inventory_transfer_recommendations'
        ];
        
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('domain')->nullable()->index()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'sales',
            'products', 
            'customers',
            'users',
            'loyalty_tiers',
            'stock_adjustments',
            'inventory_locations',
            'inventory_transfer_recommendations'
        ];
        
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }
    }
};