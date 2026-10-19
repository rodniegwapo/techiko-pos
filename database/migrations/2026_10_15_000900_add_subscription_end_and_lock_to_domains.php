<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an organization's subscription ends (only warns; nothing locks by itself), and when a super
     * admin locked it for not paying (its users then only see the "on hold" page).
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->date('subscription_ends_at')->nullable()->after('subscription_started_at');
            $table->timestamp('locked_at')->nullable()->after('subscription_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['subscription_ends_at', 'locked_at']);
        });
    }
};
