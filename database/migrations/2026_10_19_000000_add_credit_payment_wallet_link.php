<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A credit (utang) payment records the store it was taken at, and one paid in cash posts a
 * "credit_payment" cash-in to that store's wallet ledger, so the expected drawer cash includes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('domain')
                ->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('wallet_cash_movement_id')->nullable()->after('payment_method')
                ->constrained('wallet_cash_movements')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment','sale_payment','sale_void','credit_payment') NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_cash_movement_id');
            $table->dropConstrainedForeignId('location_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')->where('kind', 'credit_payment')->update(['kind' => 'adjustment']);
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment','sale_payment','sale_void') NOT NULL");
        }
    }
};
