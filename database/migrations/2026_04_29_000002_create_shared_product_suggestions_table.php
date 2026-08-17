<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_product_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('barcode')->index();
            $table->json('snapshot')->nullable();
            $table->foreignId('submitted_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('shared_product_suggestions', function (Blueprint $table) {
            $table->index(['domain', 'barcode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_product_suggestions');
    }
};
