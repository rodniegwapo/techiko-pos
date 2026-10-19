<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether organizations can pick a plan on the billing page, now set by super admins on the Plans
     * page instead of config (manual_billing.billing_hidden_tier_slugs is only read here, once).
     */
    public function up(): void
    {
        Schema::table('service_tiers', function (Blueprint $table) {
            $table->boolean('shown_on_billing')->default(true)->after('is_active');
        });

        $hidden = array_map('strtolower', (array) config('manual_billing.billing_hidden_tier_slugs', []));
        if ($hidden !== []) {
            DB::table('service_tiers')->whereIn(DB::raw('LOWER(slug)'), $hidden)->update(['shown_on_billing' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('service_tiers', function (Blueprint $table) {
            $table->dropColumn('shown_on_billing');
        });
    }
};
