<?php

namespace App\Ai\Agents;

use App\Services\Ai\FinanceTools;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * Answers an owner's own question ("Ask about your business"). It calls the finance tools for the
 * figures it needs, as many rounds as it takes within maxSteps, then answers in plain words.
 */
class FinanceQuestionAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** Steps (tool calls and the answer) a question may take before giving up. */
    public const MAX_STEPS = 8;

    /**
     * @param  list<array{question: string, answer: string}>  $history  The last few questions and answers, for follow-ups.
     * @param  array<string, string>  $providers  Providers to try in order, each with its model.
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $history,
        private readonly FinanceTools $financeTools,
        private readonly array $providers,
        private readonly int $timeout,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        $messages = [];
        foreach (array_slice($this->history, -4) as $turn) {
            $messages[] = new Message('user', (string) $turn['question']);
            $messages[] = new Message('assistant', (string) $turn['answer']);
        }

        return $messages;
    }

    public function tools(): iterable
    {
        return $this->financeTools->tools();
    }

    /** @return array<string, string> Providers to try in order, each with its model. */
    public function provider(): array
    {
        return $this->providers;
    }

    public function model(): string
    {
        return (string) reset($this->providers);
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function maxSteps(): int
    {
        return self::MAX_STEPS;
    }

    public function maxTokens(): int
    {
        return 8000;
    }
}
