<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which count a count was, for the drawer and Drawer history: spot_check (an optional count during
 * the day), end_of_day (the store's final count) or shift (a cashier ending their own shift).
 * Older counts stay null; the pages tell a cashier's shift from its register_shift_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_cash_count_submissions', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('register_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_cash_count_submissions', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
