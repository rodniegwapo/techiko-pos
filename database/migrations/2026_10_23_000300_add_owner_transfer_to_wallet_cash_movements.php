<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash the owner takes from one store's drawer can go into another store's drawer: the "out" at
 * one store and the "owner_transfer_in" at the other share a transfer_group, so each is traceable.
 */
return new class extends Migration
{
    private const KINDS = "'cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment','sale_payment','sale_void','credit_payment'";

    public function up(): void
    {
        Schema::table('wallet_cash_movements', function (Blueprint $table) {
            $table->uuid('transfer_group')->nullable()->after('kind')->index();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallet_cash_movements MODIFY kind ENUM('.self::KINDS.",'owner_transfer_in') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')->where('kind', 'owner_transfer_in')->update(['kind' => 'cash_sale_topup']);
            DB::statement('ALTER TABLE wallet_cash_movements MODIFY kind ENUM('.self::KINDS.') NOT NULL');
        }

        Schema::table('wallet_cash_movements', function (Blueprint $table) {
            $table->dropIndex(['transfer_group']);
            $table->dropColumn('transfer_group');
        });
    }
};
