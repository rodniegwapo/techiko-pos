<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Tier System not reflecting from Tier Management": customers move between tiers when a
 * threshold changes or a tier is switched off, and follow a renamed tier.
 */
class LoyaltyTierChangesReachCustomersTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create([
            'name' => 'Tier Org',
            'name_slug' => 'tier-org-'.Str::lower(Str::random(8)),
        ]);
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true]);
    }

    private function tier(string $name, float $threshold, int $order): LoyaltyTier
    {
        return LoyaltyTier::query()->create([
            'domain' => $this->domain->name_slug,
            'name' => $name,
            'display_name' => ucfirst($name),
            'multiplier' => 1,
            'spending_threshold' => $threshold,
            'color' => '#123456',
            'sort_order' => $order,
            'is_active' => true,
        ]);
    }

    private function customer(float $spent, string $tier): Customer
    {
        return Customer::query()->create([
            'domain' => $this->domain->name_slug,
            'name' => 'Customer '.Str::random(4),
            'lifetime_spent' => $spent,
            'tier' => $tier,
        ]);
    }

    private function update(LoyaltyTier $tier, array $changes)
    {
        return $this->actingAs($this->admin)->putJson(route('domains.loyalty.tiers.update', [
            'domain' => $this->domain->name_slug,
            'tier' => $tier->id,
        ]), array_merge($tier->only(['name', 'display_name', 'multiplier', 'spending_threshold', 'color', 'sort_order', 'is_active']), $changes));
    }

    public function test_lowering_a_threshold_moves_customers_up(): void
    {
        $this->tier('bronze', 0, 1);
        $silver = $this->tier('silver', 20000, 2);
        $customer = $this->customer(15000, 'bronze');

        $this->update($silver, ['spending_threshold' => 10000])->assertOk();

        $this->assertSame('silver', $customer->fresh()->tier);
    }

    public function test_switching_a_tier_off_moves_its_customers_down(): void
    {
        $this->tier('bronze', 0, 1);
        $gold = $this->tier('gold', 50000, 3);
        $customer = $this->customer(60000, 'gold');

        $this->update($gold, ['is_active' => false])->assertOk();

        $this->assertSame('bronze', $customer->fresh()->tier);
    }

    public function test_customers_follow_a_renamed_tier(): void
    {
        $this->tier('bronze', 0, 1);
        $silver = $this->tier('silver', 20000, 2);
        $customer = $this->customer(25000, 'silver');

        $this->update($silver, ['name' => 'regular', 'display_name' => 'Regular'])->assertOk();

        $this->assertSame('regular', $customer->fresh()->tier);
        $this->assertSame('Regular', $customer->fresh()->getTierInfo()['name']);
    }

    public function test_a_new_tier_takes_in_the_customers_who_reach_it(): void
    {
        $this->tier('bronze', 0, 1);
        $customer = $this->customer(30000, 'bronze');

        $this->actingAs($this->admin)->postJson(route('domains.loyalty.tiers.store', ['domain' => $this->domain->name_slug]), [
            'name' => 'vip', 'display_name' => 'VIP', 'multiplier' => 2, 'spending_threshold' => 25000,
            'color' => '#AA00AA', 'sort_order' => 2, 'is_active' => true,
        ])->assertCreated();

        $this->assertSame('vip', $customer->fresh()->tier);
    }

    public function test_another_organizations_customers_are_left_alone(): void
    {
        $this->tier('bronze', 0, 1);
        $silver = $this->tier('silver', 20000, 2);
        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(8))]);
        $outsider = Customer::query()->create([
            'domain' => $other->name_slug, 'name' => 'Outsider', 'lifetime_spent' => 15000, 'tier' => 'bronze',
        ]);

        $this->update($silver, ['spending_threshold' => 10000])->assertOk();

        $this->assertSame('bronze', $outsider->fresh()->tier);
    }
}
