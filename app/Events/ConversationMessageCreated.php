<?php

namespace App\Events;

use App\Models\ConversationMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ConversationMessage $message)
    {
        $this->message->load('author');
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        $a = $this->message->author;

        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'body' => $this->message->body,
                'author_user_id' => $this->message->author_user_id,
                'created_at' => $this->message->created_at?->toIso8601String(),
                'read_by_staff_at' => $this->message->read_by_staff_at?->toIso8601String(),
                'author' => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'email' => $a->email,
                ],
            ],
        ];
    }
}
