<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Product\Product;
use App\Models\ServiceTier;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DomainSubscriptionService
{
    public const FREE_TIER_MAX_PRODUCTS = 10;

    public function effectiveMaxProducts(Domain $domain): ?int
    {
        $domain->loadMissing('currentServiceTier');
        $tier = $domain->currentServiceTier;

        if (! $domain->current_service_tier_id || ! $tier) {
            return self::FREE_TIER_MAX_PRODUCTS;
        }

        return $tier->max_products;
    }

    public function effectiveMaxUsers(Domain $domain): ?int
    {
        $domain->loadMissing('currentServiceTier');
        $tier = $domain->currentServiceTier;

        if (! $domain->current_service_tier_id || ! $tier) {
            return null;
        }

        return $tier->max_users;
    }

    public function productCount(string $domainSlug): int
    {
        return Product::query()->where('domain', $domainSlug)->count();
    }

    public function tenantUserCount(string $domainSlug): int
    {
        return User::query()
            ->where('domain', $domainSlug)
            ->where('is_super_user', false)
            ->count();
    }

    public function assertCanCreateProduct(Domain $domain): void
    {
        $max = $this->effectiveMaxProducts($domain);

        if ($max === null) {
            return;
        }

        if ($this->productCount($domain->name_slug) >= $max) {
            throw ValidationException::withMessages([
                'plan' => [__('You have reached the product limit for your plan (:max products). Subscribe to unlock more.', ['max' => $max])],
            ]);
        }
    }

    public function assertCanCreateUser(Domain $domain): void
    {
        $max = $this->effectiveMaxUsers($domain);

        if ($max === null) {
            return;
        }

        if ($this->tenantUserCount($domain->name_slug) >= $max) {
            throw ValidationException::withMessages([
                'plan' => [__('Your plan allows :max users (including admins). Upgrade your servicing plan or remove a user.', ['max' => $max])],
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function subscriptionPropsForFrontend(Domain $domain): array
    {
        $domain->loadMissing('currentServiceTier');
        $productCount = $this->productCount($domain->name_slug);
        $userCount = $this->tenantUserCount($domain->name_slug);
        $maxProducts = $this->effectiveMaxProducts($domain);
        $maxUsers = $this->effectiveMaxUsers($domain);

        return [
            'is_paid' => (bool) $domain->current_service_tier_id,
            'tier_name' => $domain->currentServiceTier?->name,
            'product_count' => $productCount,
            'max_products' => $maxProducts,
            'products_at_capacity' => $maxProducts !== null && $productCount >= $maxProducts,
            'user_count' => $userCount,
            'max_users' => $maxUsers,
            'users_at_capacity' => $maxUsers !== null && $userCount >= $maxUsers,
            'free_product_limit' => self::FREE_TIER_MAX_PRODUCTS,
            'billing_url' => route('domains.billing.gcash.index', ['domain' => $domain->name_slug]),
        ];
    }

    public function grantServicingTier(string $domainSlug, int $serviceTierId): void
    {
        ServiceTier::query()->where('is_active', true)->findOrFail($serviceTierId);

        $domain = Domain::query()->where('name_slug', $domainSlug)->first();
        if (! $domain) {
            return;
        }

        $domain->update([
            'current_service_tier_id' => $serviceTierId,
            'subscription_started_at' => now(),
        ]);
    }
}
