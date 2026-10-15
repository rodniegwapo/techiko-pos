<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every subscription payment an organization made (recorded by a super admin, an approved GCash
     * request, or PayMongo). Each one moves the organization's next payment date (domains.subscription_ends_at)
     * from period_start to period_end.
     */
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('service_tier_id')->nullable()->constrained('service_tiers')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedTinyInteger('months')->default(1);
            $table->string('method', 30)->comment('bank_transfer | gcash | paymongo');
            $table->string('reference_number', 100)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->date('paid_on');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('previous_ends_at')->nullable()->comment('the next payment date before this payment; restored when it is voided');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manual_payment_request_id')->nullable()->constrained('manual_payment_requests')->nullOnDelete();
            $table->foreignId('paymongo_checkout_id')->nullable()->constrained('paymongo_checkouts')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['domain', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
