<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's email is theirs within one organization.
 *
 * The address was unique across the whole installation, so one organization registering a customer
 * took that address away from every other organization — two businesses sharing this system could
 * not both know the same person. It only has to be free within the organization now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_email_unique');
            $table->unique(['domain', 'email'], 'customers_domain_email_unique');
        });
    }

    public function down(): void
    {
        // The address has to be unique on its own again: keep the oldest customer of each.
        $keep = DB::table('customers')
            ->selectRaw('MIN(id) as id')
            ->whereNotNull('email')
            ->groupBy('email')
            ->pluck('id');

        DB::table('customers')
            ->whereNotNull('email')
            ->whereNotIn('id', $keep)
            ->update(['email' => null]);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_domain_email_unique');
            $table->unique('email', 'customers_email_unique');
        });
    }
};
