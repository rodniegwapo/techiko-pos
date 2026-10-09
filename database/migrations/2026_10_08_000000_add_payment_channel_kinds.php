<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment card types become payment channels: a card terminal, an e-wallet (GCash, Maya) or a bank.
 * Sales can now be paid by bank, and e-wallet and bank sales can keep the payment's reference number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_card_types', function (Blueprint $table) {
            $table->enum('kind', ['card', 'ewallet', 'bank'])->default('card')->after('name');
            $table->index(['domain', 'location_id', 'kind'], 'pct_domain_location_kind_idx');
        });

        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'card', 'e-wallet', 'bank', 'credit') DEFAULT 'cash'");

        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_reference', 100)->nullable()->after('payment_card_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('payment_reference');
        });

        DB::statement("UPDATE sales SET payment_method = 'e-wallet' WHERE payment_method = 'bank'");
        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'card', 'e-wallet', 'credit') DEFAULT 'cash'");

        Schema::table('payment_card_types', function (Blueprint $table) {
            $table->dropIndex('pct_domain_location_kind_idx');
            $table->dropColumn('kind');
        });
    }
};
