<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->text('reply_text')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
