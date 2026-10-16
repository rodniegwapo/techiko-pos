<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One user's chat with the AI assistant. Deleted 90 days after its last message. */
class AssistantConversation extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 90;

    protected $fillable = ['domain', 'user_id', 'title', 'language', 'last_message_at'];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }

    /** Chats are private: only the user who had them, in that organization. */
    public function scopeOwnedBy(Builder $query, User $user, Domain $domain): Builder
    {
        return $query->where('user_id', $user->id)->where('domain', $domain->name_slug);
    }

    public function prunable(): Builder
    {
        return static::query()->where('last_message_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
