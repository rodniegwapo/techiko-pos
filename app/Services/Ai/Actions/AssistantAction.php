<?php

namespace App\Services\Ai\Actions;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A change the AI assistant can make for the user. The AI only proposes it (prepare); it runs
 * after the user presses Confirm, through the same controller the normal page uses (run).
 */
interface AssistantAction
{
    /** Tool name without the "propose_" prefix, e.g. "create_product". */
    public function name(): string;

    /** The route permission the user needs, e.g. "products.store". */
    public function permission(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array;

    /** Checks the AI's input and describes the change. Throws ActionInputException when it can't be offered. */
    public function prepare(array $input, ActionContext $context): PreparedAction;

    public function run(array $input, ActionContext $context): ActionResult;
}
