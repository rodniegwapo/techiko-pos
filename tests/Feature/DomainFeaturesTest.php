<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDomainFeature;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\Finance\FinancialSnapshot;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Finance and AI support are given per organization by a super admin. Until then they are
 * hidden (menu, buttons) and closed (the domain.feature middleware), and AI needs Finance.
 */
class DomainFeaturesTest extends TestCase
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
    }

    /** @return array{domain: Domain, user: User} */
    private function seedOrg(array $features = []): array
    {
        $domain = Domain::query()->create([
            'name' => 'Feature Org',
            'name_slug' => 'feature-org-'.Str::lower(Str::random(8)),
            'settings' => ['sales' => ['hide_out_of_stock' => true]] + ($features ? ['features' => $features] : []),
        ]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store',
            'code' => 'FO-'.Str::upper(Str::random(6)),
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

    /** Finance, which a super admin can turn on, and the AI assistant, which every plan has (the rest come with plans, see PlanFeaturesTest). */
    private function financeAndAi(Domain $domain): array
    {
        return array_intersect_key($domain->features(), ['finance' => 0, 'ai' => 0]);
    }

    private function url(string $name, Domain $domain): string
    {
        return route($name, ['domain' => $domain->name_slug]);
    }

    public function test_finance_is_off_until_turned_on_and_ai_is_always_on(): void
    {
        ['domain' => $domain] = $this->seedOrg();

        $this->assertSame(['finance' => false, 'ai' => true], $this->financeAndAi($domain), 'the AI assistant comes with every plan');
    }

    public function test_finance_pages_are_closed_while_finance_is_off(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedOrg();

        $this->actingAs($user)->get($this->url('domains.finance.dashboard', $domain))
            ->assertRedirect()
            ->assertSessionHas('notice', EnsureDomainFeature::MESSAGES['finance']);

        $this->actingAs($user)->getJson($this->url('domains.expenses.index', $domain))->assertForbidden();
        $this->actingAs($user)->getJson($this->url('domains.vat-report.index', $domain))->assertForbidden();
        $this->actingAs($user)->getJson($this->url('domains.profit-loss.index', $domain))->assertForbidden();
        $this->actingAs($user)->postJson($this->url('domains.finance.other-income.store', $domain), [])->assertForbidden();
    }

    public function test_finance_pages_open_once_finance_is_on(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedOrg(['finance' => true]);

        $this->actingAs($user)->get($this->url('domains.finance.dashboard', $domain))->assertOk();
        $this->actingAs($user)->get($this->url('domains.expenses.index', $domain))->assertOk();
    }

    public function test_finance_ai_comes_with_finance(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedOrg(['finance' => false]);

        $this->actingAs($user)->postJson($this->url('domains.finance.explain', $domain), [])
            ->assertForbidden()
            ->assertJsonPath('message', EnsureDomainFeature::MESSAGES['finance']);
        $this->actingAs($user)->postJson($this->url('domains.finance.ask', $domain), [])->assertForbidden();

        // With Finance on, the request reaches the controller (which then checks its own input).
        $domain->update(['settings' => ['features' => ['finance' => true]]]);
        $this->assertNotSame(403, $this->actingAs($user)->postJson($this->url('domains.finance.explain', $domain), [])->status());
        $this->assertNotSame(403, $this->actingAs($user)->postJson($this->url('domains.finance.ask', $domain), [])->status());
    }

    public function test_ai_assistant_does_not_need_finance(): void
    {
        ['domain' => $domain] = $this->seedOrg(['finance' => false]);

        $this->assertTrue($domain->hasFeature('ai'));
        $this->assertFalse($domain->hasFeature('finance'));
    }

    public function test_a_super_admin_turns_finance_on_and_other_settings_stay(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedOrg();

        $this->actingAs($user)
            ->patch(route('domains.features.update', $domain), ['finance' => true])
            ->assertRedirect()
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame(['finance' => true, 'ai' => true], $this->financeAndAi($domain));
        $this->assertTrue($domain->salesHidesOutOfStock(), 'sales settings are kept');

        $this->actingAs($user)->patch(route('domains.features.update', $domain), ['finance' => false]);
        $this->assertSame(['finance' => false, 'ai' => true], $this->financeAndAi($domain->fresh()));
    }

    public function test_an_old_ai_switch_left_off_does_not_turn_the_assistant_off(): void
    {
        ['domain' => $domain] = $this->seedOrg(['finance' => true, 'ai' => false]);

        $this->assertTrue($domain->hasFeature('ai'));
    }

    public function test_only_a_super_admin_can_change_features(): void
    {
        ['domain' => $domain] = $this->seedOrg();
        $owner = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);

        $this->actingAs($owner)
            ->patchJson(route('domains.features.update', $domain), ['finance' => true])
            ->assertForbidden();

        $this->assertFalse($domain->fresh()->hasFeature('finance'));
    }

    public function test_pages_know_which_features_the_organization_has(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedOrg(['finance' => true]);

        $this->actingAs($user)->get(route('domains.show', $domain))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Domains/Show')
                ->where('domain.data.name', 'Feature Org')
                ->where('domainFeatures.finance', true)
                ->where('domainFeatures.ai', true));
    }

    public function test_snapshots_skip_organizations_without_finance(): void
    {
        ['domain' => $off] = $this->seedOrg();
        ['domain' => $on] = $this->seedOrg(['finance' => true]);

        $this->artisan('finance:snapshot')->assertSuccessful();

        $this->assertFalse(FinancialSnapshot::query()->where('domain', $off->name_slug)->exists());
        $this->assertTrue(FinancialSnapshot::query()->where('domain', $on->name_slug)->exists());
    }
}
