<?php

namespace Tests\Feature\Ai;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\AiUsage;
use App\Models\Domain;
use App\Models\ServiceTier;
use App\Models\User;
use App\Services\Ai\AiCreditService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The organization's AI usage page and the super admin's plan & AI credits controls. */
class AiUsagePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, UserPermissionCheckMiddleware::class, ValidateCsrfToken::class]);
        config(['ai_billing.free_monthly_credits' => 30]);
    }

    private function tier(?int $credits = 500): ServiceTier
    {
        return ServiceTier::query()->create([
            'slug' => 'tier-'.Str::lower(Str::random(6)), 'name' => 'Basic servicing', 'amount' => '499.00',
            'sort_order' => 1, 'is_active' => true, 'ai_monthly_credits' => $credits,
        ]);
    }

    private function domain(bool $ai = true, array $attributes = []): Domain
    {
        return Domain::query()->create([
            'name' => 'Usage Org',
            'name_slug' => 'usage-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => $ai]],
            ...$attributes,
        ]);
    }

    private function member(Domain $domain, bool $super = false): User
    {
        return User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => $super]);
    }

    public function test_the_override_beats_the_plan(): void
    {
        $service = app(AiCreditService::class);
        $tier = $this->tier(500);

        $this->assertSame(500, $service->monthlyAllowance($this->domain(attributes: ['current_service_tier_id' => $tier->id])));
        $this->assertSame(800, $service->monthlyAllowance($this->domain(attributes: ['current_service_tier_id' => $tier->id, 'ai_monthly_credits_override' => 800])));
        $this->assertSame(0, $service->monthlyAllowance($this->domain(attributes: ['ai_monthly_credits_override' => 0])));
        $this->assertSame(30, $service->monthlyAllowance($this->domain()));

        $this->assertSame('override', $service->allowanceSource($this->domain(attributes: ['ai_monthly_credits_override' => 5])));
        $this->assertSame('free', $service->allowanceSource($this->domain()));
    }

    public function test_the_page_shows_the_plan_and_where_the_credits_went_without_costs(): void
    {
        $domain = $this->domain(attributes: ['current_service_tier_id' => $this->tier(500)->id]);
        $user = $this->member($domain);
        AiUsage::query()->create(['domain' => $domain->name_slug, 'user_id' => $user->id, 'feature' => 'assistant', 'credits' => 1, 'cost_usd' => 0.01]);
        AiUsage::query()->create(['domain' => $domain->name_slug, 'user_id' => null, 'feature' => 'finance_explain', 'credits' => 2, 'cost_usd' => 0.02]);
        AiUsage::query()->create(['domain' => 'another-org', 'user_id' => null, 'feature' => 'assistant', 'credits' => 9]);

        $response = $this->actingAs($user)->get(route('domains.ai-usage.index', ['domain' => $domain->name_slug]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('AiUsage/Index')
            ->where('plan.name', 'Basic servicing')
            ->where('aiCredits.allowance', 500)
            ->where('aiCredits.used', 3)
            ->has('usage.daily', 30)
            ->where('usage.daily.29.credits', 3)
            ->has('usage.weekly', 12)
            ->where('usage.weekly.11.credits', 3)
            ->has('usage.monthly', 12)
            ->where('usage.monthly.11.credits', 3)
            ->where('byUser.0.name', 'System')
            ->where('byUser.0.credits', 2)
            ->where('byUser.1.name', $user->name)
            ->has('recent.data', 2)
            ->where('recent.data.0.feature', 'finance_explain')
            ->etc());

        $this->assertStringNotContainsString('cost', json_encode($response->viewData('page')['props']));
    }

    public function test_usage_is_grouped_by_day_week_and_month(): void
    {
        $this->travelTo('2026-10-15 10:00:00'); // a Thursday
        $domain = $this->domain();
        $use = function (string $at, int $credits) use ($domain) {
            AiUsage::query()->create(['domain' => $domain->name_slug, 'feature' => 'assistant', 'credits' => $credits])
                ->forceFill(['created_at' => $at])->save();
        };
        $use('2026-10-15 09:00:00', 1); // today
        $use('2026-10-12 09:00:00', 2); // Monday this week
        $use('2026-10-11 09:00:00', 4); // Sunday last week
        $use('2026-08-20 09:00:00', 8); // before the 30 days, within the 12 weeks
        $use('2025-10-31 09:00:00', 16); // 12 months ago: dropped

        $usage = app(AiCreditService::class)->usageOverTime($domain);

        $this->assertSame(['date' => '2026-10-15', 'credits' => 1], $usage['daily'][29]);
        $this->assertSame(['date' => '2026-10-12', 'credits' => 2], $usage['daily'][26]);
        $this->assertSame(7, array_sum(array_column($usage['daily'], 'credits')));

        $this->assertSame(['date' => '2026-10-12', 'credits' => 3], $usage['weekly'][11]);
        $this->assertSame(['date' => '2026-10-05', 'credits' => 4], $usage['weekly'][10]);
        $this->assertSame(15, array_sum(array_column($usage['weekly'], 'credits')));

        $this->assertSame(['date' => '2026-10-01', 'credits' => 7], $usage['monthly'][11]);
        $this->assertSame(['date' => '2026-08-01', 'credits' => 8], $usage['monthly'][9]);
        $this->assertSame('2025-11-01', $usage['monthly'][0]['date']);
        $this->assertSame(15, array_sum(array_column($usage['monthly'], 'credits')));
    }

    public function test_the_page_needs_the_ai_feature(): void
    {
        $domain = $this->domain(ai: false);

        $this->actingAs($this->member($domain))
            ->getJson(route('domains.ai-usage.index', ['domain' => $domain->name_slug]))
            ->assertForbidden();
    }

    public function test_a_super_admin_sets_the_plan_and_custom_credits(): void
    {
        $tier = $this->tier(500);
        $domain = $this->domain();
        $admin = $this->member($domain, super: true);
        $url = route('domains.subscription.update', ['domain' => $domain->name_slug]);

        $this->actingAs($admin)->patch($url, ['service_tier_id' => $tier->id, 'ai_monthly_credits_override' => 800])->assertRedirect();
        $domain->refresh();
        $this->assertSame($tier->id, $domain->current_service_tier_id);
        $this->assertNotNull($domain->subscription_started_at);
        $this->assertSame(800, app(AiCreditService::class)->monthlyAllowance($domain));

        // Back to the free plan, following its credits.
        $this->actingAs($admin)->patch($url, ['service_tier_id' => null, 'ai_monthly_credits_override' => null])->assertRedirect();
        $domain->refresh();
        $this->assertNull($domain->current_service_tier_id);
        $this->assertNull($domain->subscription_started_at);
        $this->assertSame(30, app(AiCreditService::class)->monthlyAllowance($domain));
    }

    public function test_only_super_admins_change_the_plan(): void
    {
        $domain = $this->domain();

        $this->actingAs($this->member($domain))
            ->patchJson(route('domains.subscription.update', ['domain' => $domain->name_slug]), ['service_tier_id' => $this->tier()->id])
            ->assertForbidden();

        $this->assertNull($domain->fresh()->current_service_tier_id);
    }

    public function test_the_org_page_shows_super_admins_the_plan_usage_and_cost(): void
    {
        $tier = $this->tier(500);
        $domain = $this->domain(attributes: ['current_service_tier_id' => $tier->id, 'ai_monthly_credits_override' => 800]);
        AiUsage::query()->create(['domain' => $domain->name_slug, 'feature' => 'assistant', 'credits' => 1, 'cost_usd' => 0.0125]);

        $this->actingAs($this->member($domain, super: true))
            ->get(route('domains.show', $domain))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Domains/Show')
                ->where('billing.service_tier_id', $tier->id)
                ->where('billing.ai_monthly_credits_override', 800)
                ->where('billing.ai_credits.allowance', 800)
                ->where('billing.ai_cost_this_month_usd', 0.0125)
                ->has('billing.tiers', 1)
                ->etc());
    }
}
