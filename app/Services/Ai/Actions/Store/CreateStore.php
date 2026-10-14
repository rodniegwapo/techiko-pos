<?php

namespace App\Services\Ai\Actions\Store;

use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\HasInputTemplate;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Adds a store or warehouse, like Inventory → Locations → Add Location. */
class CreateStore implements AssistantAction, HasInputTemplate
{
    public function __construct(private readonly ControllerCall $call) {}

    public function templateTitle(): string
    {
        return 'Add store';
    }

    public function templateGuide(): ?string
    {
        return 'add-store';
    }

    public function template(ActionContext $context): array
    {
        return [
            ['label' => 'Name', 'required' => true, 'hint' => null],
            ['label' => 'Code', 'required' => true, 'hint' => 'up to 10 characters, e.g. BR2'],
            ['label' => 'Type', 'required' => false, 'hint' => 'store or warehouse'],
            ['label' => 'Address', 'required' => false, 'hint' => null],
            ['label' => 'Contact person', 'required' => false, 'hint' => null],
            ['label' => 'Phone', 'required' => false, 'hint' => null],
            ['label' => 'Email', 'required' => false, 'hint' => null],
        ];
    }

    public function name(): string
    {
        return 'create_store';
    }

    public function permission(): string
    {
        return 'inventory.locations.store';
    }

    public function description(): string
    {
        return 'Propose adding a store, branch or warehouse. Name and a short unique code (up to 10 characters, e.g. BR2) are required.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Store name')->required(),
            'code' => $schema->string()->description('Short code, up to 10 characters, e.g. MAIN or BR2')->required(),
            'type' => $schema->string()->enum(['store', 'warehouse'])->description('store (sells) or warehouse (stock only); default store'),
            'address' => $schema->string()->description('Address (optional)'),
            'contact_person' => $schema->string()->description('Contact person (optional)'),
            'phone' => $schema->string()->description('Phone number (optional)'),
            'email' => $schema->string()->description('Email address (optional)'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $data = StoreFields::normalise($input);
        if (($data['name'] ?? '') === '' || ($data['code'] ?? '') === '') {
            throw new ActionInputException('The store name and a short code (up to 10 characters) are required.');
        }
        $data['type'] ??= 'store';
        $data['is_active'] = true;

        return new PreparedAction('Add store', $data, StoreFields::describe($data), 'Add store');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        return $this->call->run('domains.inventory.locations.store', $input, ['domain' => $context->domain], $context->user, 'Added the store "'.$input['name'].'".');
    }
}
