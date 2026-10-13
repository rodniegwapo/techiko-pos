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

    public function __construct(
        private readonly string $instructions,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function maxTokens(): int
    {
        return 4000;
    }
}
