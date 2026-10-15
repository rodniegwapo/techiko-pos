<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\Permission;
use App\Models\ServiceTier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Super admins set when an organization's subscription starts and ends, and lock one that hasn't paid. */
class DomainSubscriptionLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::query()->create([
            'name' => 'Lock Org',
            'name_slug' => 'lock-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => true]],
            ...$attributes,
        ]);
    }

    private function tier(): ServiceTier
    {
        return ServiceTier::query()->create([
            'slug' => 'tier-'.Str::lower(Str::random(6)), 'name' => 'Basic', 'amount' => '499.00', 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['domain' => null, 'is_super_user' => true]);
    }

    private function staff(Domain $domain): User
    {
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);
        foreach (['dashboard', 'assistant.ask', 'sales.offline-sync', 'billing.servicing.index'] as $routeName) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => $routeName, 'guard_name' => 'web'], ['name' => 'Test '.$routeName]));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_super_admin_sets_the_subscription_dates(): void
    {
        $domain = $this->domain();
        $tier = $this->tier();
        $url = route('domains.subscription.update', $domain);

        $this->actingAs($this->superAdmin())->patch($url, [
            'service_tier_id' => $tier->id, 'subscription_started_at' => '2026-10-01', 'subscription_ends_at' => '2026-10-31',
        ])->assertSessionHasNoErrors();
        $domain->refresh();
        $this->assertSame('2026-10-01', $domain->subscription_started_at->toDateString());
        $this->assertSame('2026-10-31', $domain->subscription_ends_at->toDateString());

        // The end can't come before the start.
        $this->actingAs($this->superAdmin())->patch($url, [
            'service_tier_id' => $tier->id, 'subscription_started_at' => '2026-10-01', 'subscription_ends_at' => '2026-09-30',
        ])->assertSessionHasErrors('subscription_ends_at');
        $this->assertSame('2026-10-31', $domain->fresh()->subscription_ends_at->toDateString());

        // Back to the free plan: no dates.
        $this->actingAs($this->superAdmin())->patch($url, ['service_tier_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($domain->fresh()->subscription_ends_at);

        // Staff can't.
        $this->actingAs($this->staff($domain))->patchJson($url, ['service_tier_id' => $tier->id])->assertForbidden();
    }

    public function test_only_a_super_admin_locks_and_unlocks(): void
    {
        $domain = $this->domain();
        $url = route('domains.lock.update', $domain);

        $this->actingAs($this->staff($domain))->patchJson($url, ['locked' => true])->assertForbidden();
        $this->assertFalse($domain->fresh()->isLocked());

        $this->actingAs($this->superAdmin())->patch($url, ['locked' => true])->assertRedirect();
        $this->assertTrue($domain->fresh()->isLocked());

        $this->actingAs($this->superAdmin())->patch($url, ['locked' => false])->assertRedirect();
        $this->assertFalse($domain->fresh()->isLocked());
    }

    public function test_a_locked_organizations_users_only_see_the_on_hold_page(): void
    {
        $domain = $this->domain(['locked_at' => now(), 'subscription_ends_at' => '2026-10-31']);
        $user = $this->staff($domain);
        $onHold = route('domains.subscription-on-hold', $domain);

        $this->actingAs($user)->get(route('domains.dashboard', $domain))->assertRedirect($onHold);
        $this->actingAs($user)->postJson(route('domains.assistant.ask', $domain), ['message' => 'Hello there'])
            ->assertStatus(423)
            ->assertJsonPath('subscription_on_hold', true);

        $this->actingAs($user)->get($onHold)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SubscriptionOnHold')
            ->where('organization', 'Lock Org')
            ->where('endedOn', '2026-10-31')
            ->where('subscriptionStatus.locked', true));

        // Offline sales still upload, and pages outside the organization still open.
        $this->assertNotSame(423, $this->actingAs($user)->postJson(route('domains.sales.offline-sync', $domain), [])->status());
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        // Another organization's user can't see its on-hold page.
        $this->actingAs($this->staff($this->domain()))->get($onHold)->assertForbidden();

        // Unlocked: the on-hold page sends them back.
        $domain->update(['locked_at' => null]);
        $this->actingAs($user)->get($onHold)->assertRedirect(route('domains.dashboard', $domain));
    }

    public function test_super_admins_are_never_locked_out(): void
    {
        $domain = $this->domain(['locked_at' => now()]);

        $this->actingAs($this->superAdmin())->get(route('domains.dashboard', $domain))->assertOk();
    }

    public function test_the_end_date_is_shared_on_the_organizations_pages_only(): void
    {
        $this->travelTo('2026-10-15 09:00:00');
        $domain = $this->domain(['subscription_ends_at' => '2026-10-20']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('domains.dashboard', $domain))->assertInertia(fn (Assert $page) => $page
            ->where('subscriptionStatus.locked', false)
            ->where('subscriptionStatus.ends_at', '2026-10-20')
            ->where('subscriptionStatus.ends_in_days', 5)
            ->etc());

        $this->actingAs($admin)->get(route('domains.show', $domain))->assertInertia(fn (Assert $page) => $page
            ->where('subscriptionStatus', null)
            ->etc());
    }
}
