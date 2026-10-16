<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\ServiceTier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the free plan leaves out (modeled on Loyverse: selling is free, depth is paid). Only while
 * plans are enforced (config features.plan_gating); until then everything stays open as before.
 */
class PlanFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
            ValidateCsrfToken::class,
        ]);
        config(['features.plan_gating' => true]);
    }

    private function tier(string $slug, string $amount, array $features): ServiceTier
    {
        return ServiceTier::query()->create([
            'slug' => $slug, 'name' => Str::title($slug).' servicing', 'amount' => $amount,
            'sort_order' => (int) $amount, 'is_active' => true, 'features' => $features,
        ]);
    }

    /** @return array{domain: Domain, user: User} */
    private function seedOrg(?ServiceTier $tier = null, array $features = []): array
    {
        $domain = Domain::query()->create([
            'name' => 'Plan Org',
            'name_slug' => 'plan-org-'.Str::lower(Str::random(8)),
            'current_service_tier_id' => $tier?->id,
            'settings' => $features ? ['features' => $features] : [],
        ]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store',
            'code' => 'PO-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $location->id,
        ]);

        return compact('domain', 'user');
    }

    private function url(string $name, Domain $domain): string
    {
        return route($name, ['domain' => $domain->name_slug]);
    }

    public function test_the_free_plan_has_no_paid_features(): void
    {
        ['domain' => $domain] = $this->seedOrg();

        $this->assertSame(array_fill_keys(Domain::FEATURES, false), $domain->features());
    }

    public function test_a_plan_gives_what_it_includes(): void
    {
        $standard = $this->tier('standard', '999.00', ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses']);
        ['domain' => $domain] = $this->seedOrg($standard);

        $this->assertTrue($domain->hasFeature('advanced_inventory'));
        $this->assertTrue($domain->hasFeature('expenses'));
        $this->assertFalse($domain->hasFeature('finance'));
        $this->assertFalse($domain->hasFeature('unknown'));
    }

    public function test_paid_pages_send_a_free_organization_to_the_plans(): void
    {
        $this->tier('standard', '999.00', ['advanced_inventory', 'expenses']);
        $this->tier('premium', '1999.00', ['advanced_inventory', 'expenses', 'finance']);
        ['domain' => $domain, 'user' => $user] = $this->seedOrg();

        $this->actingAs($user)->get($this->url('domains.inventory.movements', $domain))
            ->assertRedirect($this->url('domains.billing.servicing.index', $domain))
            ->assertSessionHas('notice', fn ($notice) => str_contains($notice, 'Standard servicing plan'));
        $this->actingAs($user)->getJson($this->url('domains.expenses.index', $domain))->assertForbidden();
        $this->actingAs($user)->getJson($this->url('domains.inventory.valuation', $domain))->assertForbidden();
        $this->actingAs($user)->postJson($this->url('domains.inventory.transfer', $domain), [])->assertForbidden();
        $this->actingAs($user)->getJson($this->url('domains.finance.dashboard', $domain))
            ->assertForbidden()
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Premium servicing plan'));
    }

    public function test_paid_pages_open_on_a_plan_that_includes_them(): void
    {
        $premium = $this->tier('premium', '1999.00', ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses', 'finance', 'ai']);
        ['domain' => $domain, 'user' => $user] = $this->seedOrg($premium);

        $this->actingAs($user)->get($this->url('domains.inventory.movements', $domain))->assertOk();
        $this->actingAs($user)->get($this->url('domains.expenses.index', $domain))->assertOk();
        $this->actingAs($user)->get($this->url('domains.finance.dashboard', $domain))->assertOk();
    }

    public function test_finance_given_by_a_super_admin_works_without_the_plan(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedOrg(null, ['finance' => true]);

        $this->actingAs($user)->get($this->url('domains.finance.dashboard', $domain))->assertOk();
        // Expenses came with Finance before they were their own feature.
        $this->actingAs($user)->get($this->url('domains.expenses.index', $domain))->assertOk();
        $this->assertFalse($domain->hasFeature('advanced_inventory'));
    }

    public function test_the_free_plan_shows_only_the_last_31_days_of_sales_and_no_export(): void
    {
        $this->travelTo('2026-10-16 10:00:00');
        ['domain' => $domain, 'user' => $user] = $this->seedOrg();

        $this->actingAs($user)
            ->get($this->url('domains.sales-history.index', $domain).'?start_date=2026-01-01&end_date=2026-10-16')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.start_date', '2026-09-16')
                ->where('filters.end_date', '2026-10-16')
                ->where('historyLimit', ['days' => 31, 'earliest_date' => '2026-09-16']));

        $this->actingAs($user)->getJson($this->url('domains.sales-history.export', $domain))->assertForbidden();
    }

    public function test_a_plan_with_full_history_is_not_cut_off(): void
    {
        $basic = $this->tier('basic', '499.00', ['sales_history_unlimited']);
        ['domain' => $domain, 'user' => $user] = $this->seedOrg($basic);

        $this->actingAs($user)
            ->get($this->url('domains.sales-history.index', $domain).'?start_date=2026-01-01&end_date=2026-01-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.start_date', '2026-01-01')
                ->where('historyLimit', null));
    }

    public function test_the_free_plan_has_one_location(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedOrg();

        $this->actingAs($user)
            ->post($this->url('domains.inventory.locations.store', $domain), [
                'name' => 'Second store', 'code' => 'SEC-1', 'type' => 'store', 'is_active' => true,
            ])
            ->assertSessionHasErrors('plan');

        $this->assertSame(1, InventoryLocation::query()->forDomain($domain->name_slug)->count());
    }

    public function test_a_multi_location_plan_adds_locations(): void
    {
        $standard = $this->tier('standard', '999.00', ['multi_location']);
        ['domain' => $domain, 'user' => $user] = $this->seedOrg($standard);

        $this->actingAs($user)
            ->post($this->url('domains.inventory.locations.store', $domain), [
                'name' => 'Second store', 'code' => 'SEC-2', 'type' => 'store', 'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, InventoryLocation::query()->forDomain($domain->name_slug)->count());
    }

    public function test_without_plan_gating_everything_but_finance_and_ai_stays_open(): void
    {
        config(['features.plan_gating' => false]);
        ['domain' => $domain, 'user' => $user] = $this->seedOrg();

        $this->assertTrue($domain->hasFeature('advanced_inventory'));
        $this->assertTrue($domain->hasFeature('sales_history_unlimited'));
        $this->assertTrue($domain->hasFeature('multi_location'));
        $this->assertFalse($domain->hasFeature('expenses'));
        $this->assertFalse($domain->hasFeature('finance'));
        $this->actingAs($user)->get($this->url('domains.inventory.movements', $domain))->assertOk();
    }

    public function test_super_admins_set_what_a_plan_includes(): void
    {
        $tier = $this->tier('standard', '999.00', []);
        $admin = User::factory()->create(['domain' => null, 'is_super_user' => true]);
        $plan = [
            'name' => 'Standard servicing', 'amount' => 999, 'ai_monthly_credits' => 1000, 'max_products' => null, 'max_users' => null,
            'is_active' => true, 'shown_on_billing' => true, 'sort_order' => 2,
        ];

        $this->actingAs($admin)->put(route('service-tiers.update', $tier), [...$plan, 'features' => ['multi_location', 'expenses']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['multi_location', 'expenses'], $tier->fresh()->features);

        $this->actingAs($admin)->put(route('service-tiers.update', $tier), [...$plan, 'features' => ['teleport']])
            ->assertSessionHasErrors('features.0');

        // Left out, the plan keeps what it includes.
        $this->actingAs($admin)->put(route('service-tiers.update', $tier), $plan)->assertSessionHasNoErrors();
        $this->assertSame(['multi_location', 'expenses'], $tier->fresh()->features);

        $this->actingAs($admin)->get(route('service-tiers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tiers.0.features', ['multi_location', 'expenses'])
                ->has('featureOptions', count(Domain::FEATURES)));
    }
}
