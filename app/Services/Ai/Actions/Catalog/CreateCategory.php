<?php

namespace App\Services\Ai\Actions\Catalog;

use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Adds a product category, like Products → Categories → Create Category. */
class CreateCategory implements AssistantAction
{
    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'create_category';
    }

    public function permission(): string
    {
        return 'categories.store';
    }

    public function description(): string
    {
        return 'Propose adding a product category.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Category name')->required(),
            'description' => $schema->string()->description('Short description (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ActionInputException('The category name is required.');
        }
        $description = trim((string) ($input['description'] ?? ''));

        $fields = [['label' => 'Name', 'value' => $name]];
        if ($description !== '') {
            $fields[] = ['label' => 'Description', 'value' => $description];
        }

        return new PreparedAction('Add category', array_filter(['name' => $name, 'description' => $description]), $fields, 'Add category');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run('domains.categories.store', $input, ['domain' => $context->domain], $context->user, 'Added the category "'.$input['name'].'".');
    }
}
