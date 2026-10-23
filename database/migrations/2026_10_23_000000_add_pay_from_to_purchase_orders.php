<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a purchase order will be paid, chosen when it is made: a store's cash drawer, a bank or
 * e-wallet account… Nothing is paid yet; Receive → Pay now starts from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('pay_method', 20)->nullable()->after('notes');
            $table->foreignId('pay_location_id')->nullable()->after('pay_method')->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('pay_account_id')->nullable()->after('pay_location_id')->constrained('financial_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_account_id');
            $table->dropConstrainedForeignId('pay_location_id');
            $table->dropColumn('pay_method');
        });
    }
};
