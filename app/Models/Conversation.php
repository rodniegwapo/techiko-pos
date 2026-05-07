<?php

namespace App\Models;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany();
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function unreadForStaffCount(): int
    {
        return $this->messages()
            ->where('author_user_id', $this->user_id)
            ->whereNull('read_by_staff_at')
            ->count();
    }

    /**
     * Count of distinct conversations with at least one customer message not yet read by staff.
     * Matches the superuser "Messages" sidebar badge in {@see HandleInertiaRequests}.
     */
    public static function unreadInboxConversationsForStaffCount(): int
    {
        return (int) static::query()
            ->whereHas('messages', function ($q) {
                $q->whereNull('read_by_staff_at')
                    ->whereColumn('author_user_id', 'conversations.user_id');
            })->count();
    }
}
