<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Questions the AI assistant could not help with, so super admins know which guides and tools
     * to add next.
     */
    public function up(): void
    {
        Schema::create('assistant_unanswered_questions', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->string('page')->nullable();
            $table->string('role')->nullable();
            $table->string('topic', 100)->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_unanswered_questions');
    }
};
