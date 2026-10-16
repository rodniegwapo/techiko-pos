<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sale paid in cash or e-wallet posts an "in" movement to the wallet ledger when completed, and
 * a "sale_void" "out" movement reverses it if the sale is later voided - closing the loop so sales
 * are traceable in the wallet the same way expenses and supplier payments already are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('wallet_cash_movement_id')->nullable()->after('void_reason')
                ->constrained('wallet_cash_movements')->nullOnDelete();
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('wallet_cash_movement_id')->nullable()->after('tendered')
                ->constrained('wallet_cash_movements')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment','sale_payment','sale_void') NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_cash_movement_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_cash_movement_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')
                ->whereIn('kind', ['sale_payment', 'sale_void'])
                ->update(['kind' => 'adjustment']);
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment') NOT NULL");
        }
    }
};
