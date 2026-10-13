<?php

namespace App\Services\Ai\Actions\Store;

use App\Models\InventoryLocation;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;

/** Finds a store of this business by the id the AI got from list_stores. */
class StoreLookup
{
    public static function find(mixed $id, ActionContext $context): InventoryLocation
    {
        $location = $id ? InventoryLocation::query()->forDomain($context->domain->name_slug)->find($id) : null;
        if (! $location) {
            throw new ActionInputException('No such store in this business. Use list_stores to get its location_id.');
        }

        return $location;
    }
}
