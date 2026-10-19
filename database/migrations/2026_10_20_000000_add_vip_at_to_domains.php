<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VIP organization (set by a super admin): every premium feature and limit, unlimited AI
 * credits, no subscription warnings, never locked. vip_at is when it was given; null = not VIP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('vip_at')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('vip_at');
        });
    }
};
