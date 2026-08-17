<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('manual_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('service_tier_id')->constrained('service_tiers')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('gcash_reference');
            $table->string('status', 32)->default('pending');
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->timestamps();

            $table->index(['domain', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_requests');
        Schema::dropIfExists('service_tiers');
    }
};
