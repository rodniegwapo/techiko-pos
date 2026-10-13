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

/** Opens or closes a store (active / inactive), like the status switch under Inventory → Locations. */
class SetStoreActive implements AssistantAction
{
    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'set_store_active';
    }

    public function permission(): string
    {
        return 'inventory.locations.toggle-status';
    }

    public function description(): string
    {
        return 'Propose activating or deactivating a store (location_id from list_stores). An inactive store is not used for sales.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'location_id' => $schema->integer()->description('Store id from list_stores')->required(),
            'active' => $schema->boolean()->description('true to activate, false to deactivate')->required(),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $location = StoreLookup::find($input['location_id'] ?? null, $context);
        $active = filter_var($input['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            throw new ActionInputException('Say whether to activate or deactivate the store.');
        }
        if ((bool) $location->is_active === $active) {
            throw new ActionInputException($location->name.' is already '.($active ? 'active' : 'inactive').'.');
        }

        return new PreparedAction($active ? 'Activate store' : 'Deactivate store', ['location_id' => $location->id, 'active' => $active], [
            ['label' => 'Store', 'value' => $location->name],
            ['label' => 'Status', 'value' => $active ? 'Inactive → Active' : 'Active → Inactive'],
        ], $active ? 'Activate' : 'Deactivate');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $location = InventoryLocation::query()->forDomain($context->domain->name_slug)->find($input['location_id']);
        if (! $location) {
            return ActionResult::failed('That store no longer exists.');
        }

        $verb = $input['active'] ? 'active' : 'inactive';
        // The page's switch flips the status; only flip it if it isn't already where the user wanted.
        if ((bool) $location->is_active === (bool) $input['active']) {
            return ActionResult::done($location->name.' is already '.$verb.'.');
        }

        return $this->call->run(
            'domains.inventory.locations.toggle-status',
            [],
            ['domain' => $context->domain, 'location' => $location],
            $context->user,
            $location->name.' is now '.$verb.'.',
        );
    }
}
