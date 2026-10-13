<?php

namespace App\Services\Ai\Actions;

use App\Models\User;
use App\Services\Ai\Actions\Catalog\CreateCategory;
use App\Services\Ai\Actions\Catalog\CreateProduct;
use App\Services\Ai\Actions\Catalog\UpdateProduct;
use App\Services\Ai\Actions\Store\CreateStore;
use App\Services\Ai\Actions\Store\SetDefaultStore;
use App\Services\Ai\Actions\Store\SetStoreActive;
use App\Services\Ai\Actions\Store\UpdateSalesSettings;
use App\Services\Ai\Actions\Store\UpdateStore;
use App\Support\RoutePermission;

/** Every change the AI assistant can make, and which of them a user's role allows. */
class ActionRegistry
{
    private const ACTIONS = [
        CreateProduct::class,
        UpdateProduct::class,
        CreateCategory::class,
        UpdateSalesSettings::class,
        CreateStore::class,
        UpdateStore::class,
        SetDefaultStore::class,
        SetStoreActive::class,
    ];

    /** @return list<AssistantAction> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::ACTIONS);
    }

    /** @return list<AssistantAction> */
    public function allowedFor(User $user): array
    {
        return array_values(array_filter($this->all(), fn (AssistantAction $action) => RoutePermission::allows($user, $action->permission())));
    }

    public function find(string $name): ?AssistantAction
    {
        foreach ($this->all() as $action) {
            if ($action->name() === $name) {
                return $action;
            }
        }

        return null;
    }
}
