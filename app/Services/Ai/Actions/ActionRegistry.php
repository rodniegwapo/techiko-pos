<?php

namespace App\Services\Ai\Actions;

use App\Models\Domain;
use App\Models\User;
use App\Services\Ai\Actions\Catalog\CreateCategory;
use App\Services\Ai\Actions\Catalog\CreateDiscount;
use App\Services\Ai\Actions\Catalog\CreateProduct;
use App\Services\Ai\Actions\Catalog\UpdateDiscount;
use App\Services\Ai\Actions\Catalog\UpdateProduct;
use App\Services\Ai\Actions\Customers\AdjustLoyaltyPoints;
use App\Services\Ai\Actions\Customers\CreateCustomer;
use App\Services\Ai\Actions\Customers\RecordCreditPayment;
use App\Services\Ai\Actions\Customers\UpdateCustomer;
use App\Services\Ai\Actions\Finance\CreateSupplier;
use App\Services\Ai\Actions\Finance\PaySupplierBill;
use App\Services\Ai\Actions\Finance\RecordExpense;
use App\Services\Ai\Actions\Finance\RecordSupplierBill;
use App\Services\Ai\Actions\Inventory\CreateStockAdjustment;
use App\Services\Ai\Actions\Inventory\ReceiveStock;
use App\Services\Ai\Actions\Store\CreatePaymentChannel;
use App\Services\Ai\Actions\Store\CreateStore;
use App\Services\Ai\Actions\Store\SetDefaultStore;
use App\Services\Ai\Actions\Store\SetStoreActive;
use App\Services\Ai\Actions\Store\UpdateSalesSettings;
use App\Services\Ai\Actions\Store\UpdateStore;
use App\Support\RouteFeature;
use App\Support\RoutePermission;

/** Every change the AI assistant can make, and which of them a user's role and business allow. */
class ActionRegistry
{
    private const ACTIONS = [
        CreateProduct::class,
        UpdateProduct::class,
        CreateCategory::class,
        CreateDiscount::class,
        UpdateDiscount::class,
        UpdateSalesSettings::class,
        CreateStore::class,
        UpdateStore::class,
        SetDefaultStore::class,
        SetStoreActive::class,
        CreatePaymentChannel::class,
        CreateCustomer::class,
        UpdateCustomer::class,
        AdjustLoyaltyPoints::class,
        RecordCreditPayment::class,
        CreateStockAdjustment::class,
        ReceiveStock::class,
        RecordExpense::class,
        CreateSupplier::class,
        RecordSupplierBill::class,
        PaySupplierBill::class,
    ];

    /** @return list<AssistantAction> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::ACTIONS);
    }

    /** @return list<AssistantAction> */
    public function allowedFor(User $user, Domain $domain): array
    {
        return array_values(array_filter($this->all(), fn (AssistantAction $action) => $this->allows($action, $user, $domain)));
    }

    /**
     * The user's role has the permission and the business has the feature the change's page needs
     * (e.g. Finance for expenses). Checked when offering the change and again on Confirm.
     */
    public function allows(AssistantAction $action, User $user, Domain $domain): bool
    {
        return RoutePermission::allows($user, $action->permission())
            && RouteFeature::allows($domain, $action->permission());
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
