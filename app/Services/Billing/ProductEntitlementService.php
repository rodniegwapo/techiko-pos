<?php

namespace App\Services\Billing;

use App\Models\Domain;
use App\Models\Product\Product;

class ProductEntitlementService
{
    public function freeTierLimit(): int
    {
        return max(0, config('billing.free_tier_product_limit', 10));
    }

    public function productCount(Domain $domain): int
    {
        return Product::query()
            ->where('domain', $domain->name_slug)
            ->count();
    }

    public function canAddProduct(Domain $domain): bool
    {
        if ($domain->subscription_active) {
            return true;
        }

        return $this->productCount($domain) < $this->freeTierLimit();
    }

    /**
     * @return array{product_count:int, product_limit:int, can_add_product:bool, subscription_active:bool, paymongo_public_key:?string}
     */
    public function inertiaPropsForDomain(Domain $domain): array
    {
        return [
            'product_count' => $this->productCount($domain),
            'product_limit' => $this->freeTierLimit(),
            'can_add_product' => $this->canAddProduct($domain),
            'subscription_active' => (bool) $domain->subscription_active,
            'paymongo_public_key' => config('paymongo.public_key') ?: null,
        ];
    }
}
