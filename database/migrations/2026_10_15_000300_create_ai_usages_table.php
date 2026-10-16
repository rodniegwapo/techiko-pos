<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature', 40)->comment('assistant | finance_question | finance_explain');
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->unsignedSmallInteger('credits');
            $table->decimal('cost_usd', 10, 6)->default(0)->comment('estimate from config/ai_billing.php prices');
            $table->timestamps();

            $table->index(['domain', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
