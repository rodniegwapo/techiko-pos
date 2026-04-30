<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paymongo_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('payment_intent_id')->unique();
            $table->string('client_key')->nullable();
            $table->string('domain');
            $table->foreignId('service_tier_id')->constrained('service_tiers')->cascadeOnDelete();
            $table->unsignedInteger('amount_centavos');
            $table->string('status', 32)->default('pending');
            $table->string('billing_email')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['domain', 'status']);
        });

        Schema::create('paymongo_webhook_event_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymongo_webhook_event_logs');
        Schema::dropIfExists('paymongo_checkouts');
    }
};
