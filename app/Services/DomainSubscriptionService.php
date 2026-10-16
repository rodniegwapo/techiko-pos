<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ServiceTier;
use App\Models\User;
use App\Support\FreePlan;
use Illuminate\Validation\ValidationException;

class DomainSubscriptionService
{
    /** Defaults for the Free plan, until super admins change it on the Plans page (App\Support\FreePlan). */
    public const FREE_TIER_MAX_PRODUCTS = 10;

    /** Users on the free plan (only while plans are enforced, see config features.plan_gating). */
    public const FREE_TIER_MAX_USERS = 2;

    public function effectiveMaxProducts(Domain $domain): ?int
    {
        if (config('features.unlimited_products', true)) {
            return null;
        }

        $domain->loadMissing('currentServiceTier');
        $tier = $domain->currentServiceTier;

        if (! $domain->current_service_tier_id || ! $tier) {
            return FreePlan::maxProducts();
        }

        return $tier->max_products;
    }

    public function effectiveMaxUsers(Domain $domain): ?int
    {
        $domain->loadMissing('currentServiceTier');
        $tier = $domain->currentServiceTier;

        if (! $domain->current_service_tier_id || ! $tier) {
            return config('features.plan_gating', false) ? FreePlan::maxUsers() : null;
        }

        return $tier->max_users;
    }

    /** One location unless the plan includes more stores (multi_location). */
    public function effectiveMaxLocations(Domain $domain): ?int
    {
        return $domain->hasFeature('multi_location') ? null : 1;
    }

    public function locationCount(string $domainSlug): int
    {
        return InventoryLocation::query()->forDomain($domainSlug)->count();
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

    public function assertCanCreateLocation(Domain $domain): void
    {
        $max = $this->effectiveMaxLocations($domain);

        if ($max === null) {
            return;
        }

        if ($this->locationCount($domain->name_slug) >= $max) {
            throw ValidationException::withMessages([
                'plan' => [__('Your plan includes :max location. Upgrade to Standard to add more stores or warehouses.', ['max' => $max])],
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

        $productsUnlimited = (bool) config('features.unlimited_products', true);

        return [
            'is_paid' => (bool) $domain->current_service_tier_id,
            'tier_name' => $domain->currentServiceTier?->name,
            'product_count' => $productCount,
            'max_products' => $maxProducts,
            'products_unlimited' => $productsUnlimited,
            'products_at_capacity' => $maxProducts !== null && $productCount >= $maxProducts,
            'user_count' => $userCount,
            'max_users' => $maxUsers,
            'users_at_capacity' => $maxUsers !== null && $userCount >= $maxUsers,
            'free_product_limit' => FreePlan::maxProducts(),
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

    /** Puts an organization back on the free plan. */
    public function clearServicingTier(Domain $domain): void
    {
        $domain->update([
            'current_service_tier_id' => null,
            'subscription_started_at' => null,
            'subscription_ends_at' => null,
        ]);
    }
}
