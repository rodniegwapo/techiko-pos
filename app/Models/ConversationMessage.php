<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'read_by_staff_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (ConversationMessage $message) {
            $message->conversation?->update([
                'last_message_at' => $message->created_at,
            ]);
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isFromCustomer(): bool
    {
        return (int) $this->author_user_id === (int) $this->conversation?->user_id;
    }
}
