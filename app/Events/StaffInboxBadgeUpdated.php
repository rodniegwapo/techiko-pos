<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StaffInboxBadgeUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $unread_conversation_count
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff-inbox')];
    }

    public function broadcastAs(): string
    {
        return 'inbox.badge';
    }

    public function broadcastWith(): array
    {
        return [
            'unread_conversation_count' => $this->unread_conversation_count,
        ];
    }
}
