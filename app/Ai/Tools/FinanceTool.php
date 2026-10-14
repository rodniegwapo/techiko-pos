<?php

namespace App\Ai\Tools;

use App\Services\Ai\FinanceTools;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * One of the finance tools (get_income_statement, get_customer_credit…) as a laravel/ai tool.
 * The figures come from FinanceTools::run(); this only gives the tool its name and schema.
 */
class FinanceTool implements Tool
{
    /**
     * @param  Closure(JsonSchema): array  $schema
     */
    public function __construct(
        private readonly FinanceTools $tools,
        private readonly string $name,
        private readonly string $description,
        private readonly Closure $schema,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function handle(Request $request): string
    {
        return $this->tools->run($this->name, $request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return ($this->schema)($schema);
    }
}
