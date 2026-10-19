<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\Permission;
use App\Models\User;
use App\Services\Ai\AiCreditService;
use App\Services\DomainSubscriptionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A VIP organization (set by a super admin): every premium feature and limit, unlimited AI
 * credits, no subscription warnings, never locked, nothing to pay.
 */
class DomainVipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        config(['features.plan_gating' => true, 'features.unlimited_products' => false]);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::query()->create([
            'name' => 'VIP Org',
            'name_slug' => 'vip-org-'.Str::lower(Str::random(8)),
            ...$attributes,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['domain' => null, 'is_super_user' => true]);
    }

    private function staff(Domain $domain): User
    {
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);
        foreach (['dashboard', 'billing.servicing.index', 'billing.paymongo.qrph.store'] as $routeName) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => $routeName, 'guard_name' => 'web'], ['name' => 'Test '.$routeName]));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_only_a_super_admin_makes_an_organization_vip_and_it_lifts_a_lock(): void
    {
        $domain = $this->domain(['locked_at' => now()]);
        $url = route('domains.vip.update', $domain);

        $this->actingAs($this->staff($domain))->patchJson($url, ['vip' => true])->assertForbidden();
        $this->assertFalse($domain->fresh()->isVip());

        $this->actingAs($this->superAdmin())->patch($url, ['vip' => true])->assertRedirect();
        $domain->refresh();
        $this->assertTrue($domain->isVip());
        $this->assertNull($domain->locked_at);

        // A VIP can't be locked.
        $this->actingAs($this->superAdmin())->patch(route('domains.lock.update', $domain), ['locked' => true])
            ->assertSessionHasErrors('locked');
        $this->assertNull($domain->fresh()->locked_at);

        $this->actingAs($this->superAdmin())->patch($url, ['vip' => false])->assertRedirect();
        $this->assertFalse($domain->fresh()->isVip());
    }

    public function test_a_vip_on_the_free_plan_gets_every_feature_and_no_limits(): void
    {
        $free = $this->domain();
        $vip = $this->domain(['vip_at' => now()]);
        $subscriptions = app(DomainSubscriptionService::class);
        $credits = app(AiCreditService::class);

        $this->assertFalse($free->hasFeature('finance'));
        $this->assertFalse($free->hasFeature('multi_location'));
        $this->assertNotNull($subscriptions->effectiveMaxUsers($free));

        foreach (Domain::FEATURES as $feature) {
            $this->assertTrue($vip->hasFeature($feature), "VIP should have {$feature}");
        }
        $this->assertNull($subscriptions->effectiveMaxUsers($vip));
        $this->assertNull($subscriptions->effectiveMaxProducts($vip));
        $this->assertNull($subscriptions->effectiveMaxLocations($vip));
        $this->assertNull($credits->monthlyAllowance($vip));
        $this->assertTrue($subscriptions->subscriptionPropsForFrontend($vip)['is_vip']);
    }

    public function test_an_ended_vip_subscription_shows_no_warning_and_never_locks(): void
    {
        $domain = $this->domain(['vip_at' => now(), 'locked_at' => now(), 'subscription_ends_at' => now()->subDays(3)->toDateString()]);
        $user = $this->staff($domain);

        $this->assertFalse($domain->isLocked());
        $this->actingAs($user)->get(route('domains.dashboard', $domain))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscriptionStatus.vip', true)
                ->where('subscriptionStatus.locked', false)
                ->where('subscriptionStatus.ends_in_days', null));
    }

    public function test_the_billing_page_shows_vip_and_nothing_can_be_paid(): void
    {
        $domain = $this->domain(['vip_at' => now(), 'subscription_ends_at' => now()->addDays(2)->toDateString()]);
        $user = $this->staff($domain);

        $this->actingAs($user)->get(route('domains.billing.servicing.index', $domain))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Billing/Gcash')
                ->where('isVip', true)
                ->where('canPay', false)
                ->where('nextPayment', null));

        $this->actingAs($user)->postJson(route('domains.billing.paymongo.qrph.store', $domain), ['service_tier_id' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization is VIP: there is nothing to pay.');
    }
}
