<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tiers', function (Blueprint $table) {
            $table->unsignedInteger('max_products')->nullable()->after('sort_order')
                ->comment('null = unlimited');
            $table->unsignedInteger('max_users')->nullable()->after('max_products')
                ->comment('tenant seats excluding super users; null = unlimited');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('current_service_tier_id')
                ->nullable()
                ->after('is_active')
                ->constrained('service_tiers')
                ->nullOnDelete();
            $table->timestamp('subscription_started_at')->nullable()->after('current_service_tier_id');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropForeign(['current_service_tier_id']);
            $table->dropColumn(['current_service_tier_id', 'subscription_started_at']);
        });

        Schema::table('service_tiers', function (Blueprint $table) {
            $table->dropColumn(['max_products', 'max_users']);
        });
    }
};
