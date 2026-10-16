<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each user's AI assistant chats, kept 90 days after the last message (AssistantConversation
     * is pruned daily). One message row is one question and the AI's answer.
     */
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('language', 20)->default('en');
            $table->timestamp('last_message_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'domain', 'last_message_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->json('links')->nullable();
            $table->json('pending_actions')->nullable();
            $table->json('forms')->nullable();
            $table->json('tools_called')->nullable();
            $table->string('page', 120)->nullable();
            $table->foreignId('ai_usage_id')->nullable()->constrained('ai_usages')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
