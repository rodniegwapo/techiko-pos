<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What each seeded plan includes (see Domain::FEATURES); super admins change it on the Plans page. */
    private const DEFAULTS = [
        'basic' => ['sales_history_unlimited'],
        'standard' => ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses'],
        'premium' => ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses', 'finance', 'ai'],
    ];

    public function up(): void
    {
        Schema::table('service_tiers', function (Blueprint $table) {
            $table->json('features')->nullable()->after('ai_monthly_credits');
        });

        foreach (self::DEFAULTS as $slug => $features) {
            DB::table('service_tiers')
                ->whereRaw('LOWER(slug) = ?', [$slug])
                ->update(['features' => json_encode($features)]);
        }
    }

    public function down(): void
    {
        Schema::table('service_tiers', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }
};
