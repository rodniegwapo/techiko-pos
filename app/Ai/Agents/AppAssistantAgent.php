<?php

namespace App\Ai\Agents;

use App\Services\Ai\AssistantTools;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * The AI assistant in the chat drawer. It looks up the how-to guides the user's role allows and
 * teaches the steps, within maxSteps rounds of tool calls.
 */
class AppAssistantAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** Steps (tool calls and the answer) a message may take before giving up. */
    public const MAX_STEPS = 8;

    /** Earlier messages and answers sent along, for follow-ups. */
    public const HISTORY_TURNS = 6;

    /**
     * @param  list<array{question: string, answer: string}>  $history
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $history,
        private readonly AssistantTools $assistantTools,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        $messages = [];
        foreach (array_slice($this->history, -self::HISTORY_TURNS) as $turn) {
            $messages[] = new Message('user', (string) $turn['question']);
            $messages[] = new Message('assistant', (string) $turn['answer']);
        }

        return $messages;
    }

    public function tools(): iterable
    {
        return $this->assistantTools->tools();
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function maxSteps(): int
    {
        return self::MAX_STEPS;
    }

    public function maxTokens(): int
    {
        return 4000;
    }
}
