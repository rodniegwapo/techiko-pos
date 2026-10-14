<?php

namespace App\Services\Ai\Actions;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;

/** Who asked the assistant to do something, in which business and which store they work in. */
final class ActionContext
{
    public function __construct(
        public readonly User $user,
        public readonly Domain $domain,
        public readonly ?InventoryLocation $location,
    ) {}
}
