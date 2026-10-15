<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\Permission;
use App\Models\ServiceTier;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Super admins set plan prices and limits on the Plans page; a new price counts from the next payment. */
class ServiceTierAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        $this->travelTo('2026-10-15 09:00:00');
    }

    private function tier(array $attributes = []): ServiceTier
    {
        return ServiceTier::query()->create([
            'slug' => 'standard-'.Str::lower(Str::random(4)), 'name' => 'Standard servicing', 'amount' => '999.00',
            'sort_order' => 2, 'is_active' => true, ...$attributes,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['domain' => null, 'is_super_user' => true]);
    }

    private function plan(array $data = []): array
    {
        return [
            'name' => 'Standard servicing', 'amount' => 1099, 'ai_monthly_credits' => 1000, 'max_products' => null, 'max_users' => 10,
            'is_active' => true, 'shown_on_billing' => true, 'sort_order' => 2, ...$data,
        ];
    }

    public function test_a_new_price_counts_from_the_next_payment(): void
    {
        $tier = $this->tier();
        $domain = Domain::query()->create(['name' => 'Plan Org', 'name_slug' => 'plan-org-'.Str::lower(Str::random(6))]);
        $payments = app(SubscriptionPaymentService::class);
        $payments->record($domain, ['service_tier_id' => $tier->id, 'amount' => 999, 'method' => 'bank_transfer', 'paid_on' => '2026-10-15', 'reference_number' => 'R1'], null);

        $this->actingAs($this->superAdmin())->put(route('service-tiers.update', $tier), $this->plan())->assertSessionHasNoErrors();

        $tier->refresh();
        $this->assertEquals(1099, $tier->amount);
        $this->assertSame([1000, null, 10], [$tier->ai_monthly_credits, $tier->max_products, $tier->max_users]);
        $this->assertStringStartsWith('standard-', $tier->slug, 'the slug never changes');
        $this->assertEquals(1099, $payments->nextPayment($domain->fresh())['amount']);
        $this->assertEquals(999, SubscriptionPayment::query()->sole()->amount, 'recorded payments keep their amounts');
    }

    public function test_a_plan_is_added_with_a_unique_slug(): void
    {
        $this->tier(['slug' => 'pro-servicing']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('service-tiers.store'), $this->plan(['name' => 'Pro servicing', 'amount' => 1499.5]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('service-tiers.store'), $this->plan(['amount' => -1]))->assertSessionHasErrors('amount');
        $this->actingAs($admin)->post(route('service-tiers.store'), $this->plan(['name' => '']))->assertSessionHasErrors('name');

        $added = ServiceTier::query()->where('name', 'Pro servicing')->sole();
        $this->assertSame('pro-servicing-2', $added->slug);
        $this->assertEquals(1499.5, $added->amount);
    }

    public function test_a_plan_hidden_from_billing_is_left_off_the_billing_pages(): void
    {
        $shown = $this->tier(['name' => 'Basic servicing']);
        $hidden = $this->tier(['name' => 'Premium servicing', 'shown_on_billing' => false]);
        $this->assertTrue(ServiceTier::isHiddenFromBilling($hidden));
        $this->assertTrue(ServiceTier::isHiddenFromBilling($hidden->slug));
        $this->assertFalse(ServiceTier::isHiddenFromBilling($shown));

        $domain = Domain::query()->create(['name' => 'Bill Org', 'name_slug' => 'bill-org-'.Str::lower(Str::random(6)), 'locked_at' => now()]);
        $admin = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => 'billing.servicing.index', 'guard_name' => 'web'], ['name' => 'Servicing']));
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->actingAs($admin->fresh())->get(route('domains.subscription-on-hold', $domain))->assertInertia(fn (Assert $page) => $page
            ->has('tiers', 1)
            ->where('tiers.0.id', $shown->id)
            ->etc());

        $domain->update(['locked_at' => null]);
        $this->actingAs($admin->fresh())->get(route('domains.billing.servicing.index', $domain))->assertInertia(fn (Assert $page) => $page
            ->has('tiers', 1)
            ->where('tiers.0.id', $shown->id)
            ->etc());
    }

    public function test_the_plans_page_lists_plans_with_how_many_organizations_use_them(): void
    {
        $tier = $this->tier();
        Domain::query()->create(['name' => 'On Plan', 'name_slug' => 'on-plan-'.Str::lower(Str::random(6)), 'current_service_tier_id' => $tier->id]);

        $this->actingAs($this->superAdmin())->get(route('service-tiers.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Plans/Index')
            ->where('tiers.0.id', $tier->id)
            ->where('tiers.0.organizations', 1)
            ->etc());
    }

    public function test_only_super_admins_manage_plans(): void
    {
        $tier = $this->tier();
        $staff = User::factory()->create(['domain' => 'some-org', 'is_super_user' => false]);

        // check.super.user sends everyone else away.
        $this->actingAs($staff)->get(route('service-tiers.index'))->assertRedirect();
        $this->actingAs($staff)->post(route('service-tiers.store'), $this->plan())->assertRedirect();
        $this->actingAs($staff)->put(route('service-tiers.update', $tier), $this->plan())->assertRedirect();
        $this->assertEquals(999, $tier->fresh()->amount);
        $this->assertSame(1, ServiceTier::query()->count());
    }
}
