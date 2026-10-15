<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question and the AI's answer, with its links, forms and change cards. Each card's
 * `state` and `result` are filled in when the user confirms or cancels it.
 */
class AssistantMessage extends Model
{
    protected $fillable = ['conversation_id', 'question', 'answer', 'links', 'pending_actions', 'forms', 'tools_called', 'page', 'ai_usage_id'];

    protected $casts = [
        'links' => 'array',
        'pending_actions' => 'array',
        'forms' => 'array',
        'tools_called' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    /** Sets what became of one change card (done, failed or cancelled). */
    public function settleAction(string $token, string $state, ?string $message, ?array $errors = null): void
    {
        $this->update([
            'pending_actions' => collect($this->pending_actions ?? [])
                ->map(fn (array $action) => ($action['token'] ?? null) === $token
                    ? [...$action, 'state' => $state, 'result' => ['message' => $message, 'errors' => $errors]]
                    : $action)
                ->all(),
        ]);
    }

    /** The answer plus what became of its cards, as the AI sees it in later messages. */
    public function answerWithOutcomes(): string
    {
        $outcomes = collect($this->pending_actions ?? [])
            ->filter(fn (array $action) => isset($action['state']))
            ->map(fn (array $action) => '['.$action['title'].': '.$action['state']
                .(! empty($action['result']['message']) ? ' - '.$action['result']['message'] : '').']');

        return mb_substr(collect([$this->answer])->merge($outcomes)->implode("\n"), 0, 4000);
    }

    /** The shape the assistant panel uses for one turn. */
    public function toTurn(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'links' => $this->links ?? [],
            'actions' => $this->pending_actions ?? [],
            'forms' => $this->forms ?? [],
        ];
    }
}
