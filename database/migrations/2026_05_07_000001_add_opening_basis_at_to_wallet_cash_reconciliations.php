<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_cash_reconciliations', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_cash_reconciliations', 'opening_basis_at')) {
                $table->dateTime('opening_basis_at')->nullable()->after('opening_source_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_cash_reconciliations', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_cash_reconciliations', 'opening_basis_at')) {
                $table->dropColumn('opening_basis_at');
            }
        });
    }
};
