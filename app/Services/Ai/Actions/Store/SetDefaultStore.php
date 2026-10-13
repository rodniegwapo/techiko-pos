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

/** Makes a store the default one, like Inventory → Locations → Set as default. */
class SetDefaultStore implements AssistantAction
{
    public function __construct(private readonly ControllerCall $call) {}

    public function name(): string
    {
        return 'set_default_store';
    }

    public function permission(): string
    {
        return 'inventory.locations.set-default';
    }

    public function description(): string
    {
        return 'Propose making a store the default one (used by staff with no assigned store). location_id from list_stores.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'location_id' => $schema->integer()->description('Store id from list_stores')->required(),
        ];
    }

    public function prepare(array $input, ActionContext $context): PreparedAction
    {
        $location = StoreLookup::find($input['location_id'] ?? null, $context);
        if ($location->is_default) {
            throw new ActionInputException($location->name.' is already the default store.');
        }
        $current = InventoryLocation::query()->forDomain($context->domain->name_slug)->where('is_default', true)->value('name');

        return new PreparedAction('Change the default store', ['location_id' => $location->id], [
            ['label' => 'Default store', 'value' => $current ? $current.' → '.$location->name : $location->name],
        ], 'Make default');
    }

    public function run(array $input, ActionContext $context): ActionResult
    {
        $location = InventoryLocation::query()->forDomain($context->domain->name_slug)->find($input['location_id']);
        if (! $location) {
            return ActionResult::failed('That store no longer exists.');
        }

        return $this->call->run(
            'domains.inventory.locations.set-default',
            [],
            ['domain' => $context->domain, 'location' => $location],
            $context->user,
            $location->name.' is now the default store.',
        );
    }
}
