<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Money moved between the drawer and a payment channel, two channels, or two stores: a
 * "transfer_out" where it left and a "transfer_in" where it arrived, sharing a transfer_group.
 */
return new class extends Migration
{
    private const KINDS = "'cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment','sale_payment','sale_void','credit_payment','owner_transfer_in'";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallet_cash_movements MODIFY kind ENUM('.self::KINDS.",'transfer_out','transfer_in') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')->where('kind', 'transfer_out')->update(['kind' => 'ewallet_transfer_out']);
            DB::table('wallet_cash_movements')->where('kind', 'transfer_in')->update(['kind' => 'ewallet_transfer_in']);
            DB::statement('ALTER TABLE wallet_cash_movements MODIFY kind ENUM('.self::KINDS.') NOT NULL');
        }
    }
};
