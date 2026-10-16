<?php

namespace App\Services\Ai\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Changes the AI proposed and the user hasn't confirmed yet. Each is kept for a short while
 * under a random token, tied to the user, business and store it was proposed for.
 */
class PendingActions
{
    public const MINUTES = 15;

    /**
     * @return array{token: string, title: string, fields: list<array{label: string, value: string}>, confirm_label: string, expires_at: string}
     */
    public function put(AssistantAction $action, PreparedAction $prepared, ActionContext $context): array
    {
        $token = Str::random(40);
        $expiresAt = now()->addMinutes(self::MINUTES);

        Cache::put($this->key($token), [
            'action' => $action->name(),
            'input' => $prepared->input,
            'title' => $prepared->title,
            'user_id' => $context->user->id,
            'domain' => $context->domain->name_slug,
            'location_id' => $context->location?->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'token' => $token,
            'title' => $prepared->title,
            'fields' => $prepared->fields,
            'confirm_label' => $prepared->confirmLabel,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Takes a pending change out (so it can only run once), or null when it expired or was used.
     *
     * @return array{action: string, input: array, title: string, user_id: int, domain: string, location_id: ?int, expires_at: string, message_id?: int}|null
     */
    public function pull(string $token): ?array
    {
        return Cache::pull($this->key($token));
    }

    public function get(string $token): ?array
    {
        return Cache::get($this->key($token));
    }

    /** Remembers which saved chat message showed this change, so confirming it can record the outcome there. */
    public function attachMessage(string $token, int $messageId): void
    {
        $pending = $this->get($token);
        if ($pending) {
            Cache::put($this->key($token), [...$pending, 'message_id' => $messageId], Carbon::parse($pending['expires_at']));
        }
    }

    private function key(string $token): string
    {
        return 'assistant-action:'.$token;
    }
}
