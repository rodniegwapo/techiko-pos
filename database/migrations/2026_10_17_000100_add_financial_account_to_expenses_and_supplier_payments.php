<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid by bank transfer or e-wallet: which of the business's accounts the money left. It only labels
 * the payment; the account balance stays what the owner enters.
 */
return new class extends Migration
{
    private const TABLES = ['expenses', 'recurring_expenses', 'supplier_payments'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('financial_account_id')->nullable()->after('payment_method')
                    ->constrained('financial_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('financial_account_id');
            });
        }
    }
};
