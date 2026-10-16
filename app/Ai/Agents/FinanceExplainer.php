<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Explains a fact sheet of figures the POS already worked out (the Finance "Explain" buttons and
 * the monthly review). FinancialAssistant supplies the instructions and the prompt.
 */
class FinanceExplainer implements Agent
{
    use Promptable;

    /**
     * @param  array<string, string>  $providers  Providers to try in order, each with its model.
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $providers,
        private readonly int $timeout,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /** @return array<string, string> */
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

    public function maxTokens(): int
    {
        return 4000;
    }
}
