<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's tier is one of the organization's own tiers from Tier Management (by name), so it
 * can't stay limited to bronze/silver/gold/platinum: a renamed or added tier was refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tier')->default('bronze')->change();
        });
    }

    public function down(): void
    {
        // Tiers outside the original four fall back to bronze before the column narrows again.
        DB::table('customers')->whereNotIn('tier', ['bronze', 'silver', 'gold', 'platinum'])->update(['tier' => 'bronze']);

        Schema::table('customers', function (Blueprint $table) {
            $table->enum('tier', ['bronze', 'silver', 'gold', 'platinum'])->default('bronze')->change();
        });
    }
};
