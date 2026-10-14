<?php

namespace App\Services\Ai\Actions\Store;

use App\Models\InventoryLocation;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;
use App\Services\Ai\Actions\ActionResult;
use App\Services\Ai\Actions\AssistantAction;
use App\Services\Ai\Actions\ControllerCall;
use App\Services\Ai\Actions\PreparedAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Changes a store's name, code, type, address or contact details, like Inventory → Locations → Edit. */
class UpdateStore implements AssistantAction
{
    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'update_store';
    }

    public function permission(): string
    {
        return 'inventory.locations.update';
    }

    public function description(): string
    {
        return 'Propose changing a store\'s details (location_id from list_stores). Send only the fields that change.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'location_id' => $schema->integer()->description('Store id from list_stores')->required(),
            'name' => $schema->string()->description('New name'),
            'code' => $schema->string()->description('New short code, up to 10 characters'),
            'type' => $schema->string()->enum(['store', 'warehouse'])->description('New type'),
            'address' => $schema->string()->description('New address'),
            'contact_person' => $schema->string()->description('New contact person'),
            'phone' => $schema->string()->description('New phone number'),
            'email' => $schema->string()->description('New email address'),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $location = StoreLookup::find($input['location_id'] ?? null, $context);
        $changes = StoreFields::normalise($input);
        if ($changes === []) {
            throw new ActionInputException('Say what to change on the store (name, code, type, address or contact details).');
        }

        $fields = [['label' => 'Store', 'value' => $location->name], ...StoreFields::describe($changes, $location->only(StoreFields::KEYS))];

        return new PreparedAction('Change store details', ['location_id' => $location->id, 'changes' => $changes], $fields, 'Save changes');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $location = InventoryLocation::query()->forDomain($context->domain->name_slug)->find($input['location_id']);
        if (! $location) {
            return ActionResult::failed('That store no longer exists.');
        }

        // The edit form sends every field, so start from what the store has now.
        $current = [...$location->only(StoreFields::KEYS), 'is_active' => (bool) $location->is_active];

        return $this->call->run(
            'domains.inventory.locations.update',
            array_filter([...$current, ...$input['changes']], fn ($v) => $v !== null),
            ['domain' => $context->domain, 'location' => $location],
            $context->user,
            'Updated the store "'.($input['changes']['name'] ?? $location->name).'".',
        );
    }
}
