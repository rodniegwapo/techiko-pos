<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('subscription_active')->default(false)->after('is_active');
            $table->string('paymongo_customer_id')->nullable()->after('subscription_active');
            $table->string('paymongo_subscription_id')->nullable()->after('paymongo_customer_id');
            $table->string('subscription_status')->nullable()->after('paymongo_subscription_id');
            $table->timestamp('subscription_current_period_end')->nullable()->after('subscription_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_active',
                'paymongo_customer_id',
                'paymongo_subscription_id',
                'subscription_status',
                'subscription_current_period_end',
            ]);
        });
    }
};
