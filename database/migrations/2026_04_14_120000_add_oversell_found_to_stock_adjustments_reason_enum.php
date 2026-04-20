<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow oversell_found on stock_adjustments.reason (MySQL ENUM).
     * Other drivers: column typically stores strings; no-op if not mysql.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE stock_adjustments MODIFY COLUMN reason ENUM(
            'physical_count',
            'damaged_goods',
            'expired_goods',
            'theft_loss',
            'supplier_error',
            'system_error',
            'promotion',
            'sample',
            'other',
            'oversell_found'
        ) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE stock_adjustments MODIFY COLUMN reason ENUM(
            'physical_count',
            'damaged_goods',
            'expired_goods',
            'theft_loss',
            'supplier_error',
            'system_error',
            'promotion',
            'sample',
            'other'
        ) NOT NULL");
    }
};
