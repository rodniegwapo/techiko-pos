<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cashier ends their shift with a manager's PIN: the count records who approved it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_cash_count_submissions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('counted_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_cash_count_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
        });
    }
};
