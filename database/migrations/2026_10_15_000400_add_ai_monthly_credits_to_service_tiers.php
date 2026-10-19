<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tiers', function (Blueprint $table) {
            $table->unsignedInteger('ai_monthly_credits')->nullable()->after('max_users')
                ->comment('AI credits shared by the organization each month; null = unlimited');
        });

        // Starting allowances for the seeded tiers (see ServiceTierSeeder).
        foreach (['basic' => 500, 'standard' => 1000, 'premium' => 2000] as $slug => $credits) {
            DB::table('service_tiers')->where('slug', $slug)->update(['ai_monthly_credits' => $credits]);
        }
    }

    public function down(): void
    {
        Schema::table('service_tiers', function (Blueprint $table) {
            $table->dropColumn('ai_monthly_credits');
        });
    }
};
