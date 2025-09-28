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
        // First, ensure all existing records have adjustment numbers
        \App\Models\StockAdjustment::whereNull('adjustment_number')
            ->orWhere('adjustment_number', '')
            ->get()
            ->each(function ($adjustment) {
                $adjustment->adjustment_number = \App\Models\StockAdjustment::generateAdjustmentNumber();
                $adjustment->saveQuietly();
            });

        // Then make the field NOT NULL again
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->string('adjustment_number')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->string('adjustment_number')->nullable()->change();
        });
    }
};