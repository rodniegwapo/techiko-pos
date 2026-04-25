<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('last_message_at');
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_by_staff_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index('read_by_staff_at');
        });

        if (Schema::hasTable('inquiries')) {
            $this->migrateInquiries();
            Schema::drop('inquiries');
        }
    }

    private function migrateInquiries(): void
    {
        $inquiries = DB::table('inquiries')->orderBy('id')->get();
        if ($inquiries->isEmpty()) {
            return;
        }

        $defaultSuperId = DB::table('users')->where('is_super_user', 1)->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        $conversationIdsByUser = [];

        foreach ($inquiries as $row) {
            $userId = (int) $row->user_id;
            if (! isset($conversationIdsByUser[$userId])) {
                $cid = DB::table('conversations')->insertGetId([
                    'user_id' => $userId,
                    'last_message_at' => $row->created_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
                $conversationIdsByUser[$userId] = $cid;
            } else {
                $cid = $conversationIdsByUser[$userId];
            }

            $lastAt = $row->created_at;
            DB::table('conversation_messages')->insert([
                'conversation_id' => $cid,
                'author_user_id' => $userId,
                'body' => $row->body,
                'read_by_staff_at' => $row->read_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            if (! empty($row->reply_text) && $row->reply_text !== '' && $defaultSuperId) {
                $authorId = (int) ($row->replied_by ?? 0) ?: (int) $defaultSuperId;
                $replyTime = $row->replied_at ?? $row->updated_at;
                $lastAt = $replyTime;
                DB::table('conversation_messages')->insert([
                    'conversation_id' => $cid,
                    'author_user_id' => $authorId,
                    'body' => $row->reply_text,
                    'read_by_staff_at' => $replyTime,
                    'created_at' => $replyTime,
                    'updated_at' => $replyTime,
                ]);
            }

            DB::table('conversations')->where('id', $cid)->update([
                'last_message_at' => $lastAt,
                'updated_at' => $lastAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');

        // inquiries table is not restored (data was moved one-way)
    }
};
