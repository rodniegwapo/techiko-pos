<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\AppAssistantAgent;
use App\Ai\Agents\FinanceExplainer;
use App\Exceptions\OutOfAiCreditsException;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\AiUsage;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\ServiceTier;
use App\Models\User;
use App\Services\Ai\AiCreditService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Monthly AI credits per organization: charged per successful answer, shown on the Billing page. */
class AiCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, UserPermissionCheckMiddleware::class, ValidateCsrfToken::class]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        config([
            'services.app_assistant.provider' => 'gemini',
            'services.finance_ai.provider' => 'gemini',
            'ai.providers.gemini.key' => 'test-key',
            'ai_billing.free_monthly_credits' => 3,
            // Finance explanations are cached; keep that cache inside this test.
            'cache.default' => 'array',
        ]);
    }

    /** @return array{domain: Domain, user: User} */
    private function org(?int $tierCredits = null, bool $paid = false): array
    {
        $tier = $paid ? ServiceTier::query()->create([
            'slug' => 'tier-'.Str::lower(Str::random(6)), 'name' => 'Test tier', 'amount' => '499.00',
            'sort_order' => 1, 'is_active' => true, 'ai_monthly_credits' => $tierCredits,
        ]) : null;

        $domain = Domain::query()->create([
            'name' => 'Credits Org',
            'name_slug' => 'credits-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => true, 'finance' => true]],
            'current_service_tier_id' => $tier?->id,
        ]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false, 'location_id' => $location->id]);
        $permission = Permission::query()->firstOrCreate(
            ['route_name' => 'assistant.ask', 'guard_name' => 'web'],
            ['name' => 'Test assistant.ask '.Str::uuid()],
        );
        $user->givePermissionTo($permission);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return ['domain' => $domain, 'user' => $user->fresh()];
    }

    private function ask(array $ctx)
    {
        return $this->actingAs($ctx['user'])->postJson(
            route('domains.assistant.ask', ['domain' => $ctx['domain']->name_slug]),
            ['message' => 'How do I add a product?'],
        );
    }

    private function usage(Domain $domain, int $credits, $at = null): void
    {
        AiUsage::query()->forceCreate([
            'domain' => $domain->name_slug, 'feature' => 'assistant', 'credits' => $credits,
            'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
        ]);
    }

    public function test_the_allowance_comes_from_the_tier_or_the_free_plan(): void
    {
        $service = app(AiCreditService::class);

        $this->assertSame(3, $service->monthlyAllowance($this->org()['domain']));
        $this->assertSame(500, $service->monthlyAllowance($this->org(500, paid: true)['domain']));
        $this->assertNull($service->monthlyAllowance($this->org(null, paid: true)['domain']));
        $this->assertNull($service->remaining($this->org(null, paid: true)['domain']));
    }

    public function test_only_this_months_usage_counts(): void
    {
        $domain = $this->org()['domain'];
        $this->usage($domain, 2, now()->subMonthNoOverflow());
        $this->usage($domain, 1);

        $this->assertSame(1, app(AiCreditService::class)->usedThisMonth($domain));
        $this->assertSame(2, app(AiCreditService::class)->remaining($domain));
    }

    public function test_recording_keeps_tokens_and_estimates_the_cost(): void
    {
        config(['ai_billing.prices.test-model' => ['input' => 1.00, 'output' => 4.00]]);
        $ctx = $this->org();

        app(AiCreditService::class)->record($ctx['domain'], $ctx['user'], 'finance_question', new TextResponse(
            'answer', new TextUsage(inputTokens: 10_000, outputTokens: 1_000, cacheReadInputTokens: 2_000), new Meta('gemini', 'test-model'),
        ));

        $row = AiUsage::query()->sole();
        $this->assertSame(2, $row->credits);
        $this->assertSame([10_000, 1_000, 2_000], [$row->input_tokens, $row->output_tokens, $row->cached_input_tokens]);
        $this->assertSame('0.014000', (string) $row->cost_usd); // 10k × $1/M + 1k × $4/M
        $this->assertSame(['gemini', 'test-model'], [$row->provider, $row->model]);
    }

    public function test_each_answer_uses_a_credit_until_the_month_is_used_up(): void
    {
        AppAssistantAgent::fake(['One.', 'Two.', 'Three.']);
        $ctx = $this->org();

        $this->ask($ctx)->assertOk()->assertJsonPath('credits_remaining', 2);
        $this->ask($ctx)->assertOk()->assertJsonPath('credits_remaining', 1);
        $this->ask($ctx)->assertOk()->assertJsonPath('credits_remaining', 0);

        $this->ask($ctx)
            ->assertStatus(402)
            ->assertJsonPath('out_of_credits', true)
            ->assertJsonPath('billing_url', route('domains.billing.servicing.index', ['domain' => $ctx['domain']->name_slug]));

        $this->assertSame(3, AiUsage::query()->where('domain', $ctx['domain']->name_slug)->count());
        AppAssistantAgent::assertPrompted(fn () => true);
    }

    public function test_the_ai_is_not_called_when_credits_are_used_up(): void
    {
        AppAssistantAgent::fake()->preventStrayPrompts();
        $ctx = $this->org();
        $this->usage($ctx['domain'], 3);

        $this->ask($ctx)->assertStatus(402);
        AppAssistantAgent::assertNeverPrompted();
    }

    public function test_a_failed_answer_is_free(): void
    {
        AppAssistantAgent::fake(fn () => throw RateLimitedException::forProvider('gemini'));
        $ctx = $this->org();

        $this->ask($ctx)->assertStatus(502);
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_unlimited_plans_are_still_tracked(): void
    {
        AppAssistantAgent::fake(['Here.']);
        $ctx = $this->org(null, paid: true);

        $this->ask($ctx)->assertOk()->assertJsonPath('credits_remaining', null);
        $this->assertSame(1, AiUsage::query()->count());
    }

    public function test_a_cached_finance_explanation_is_free(): void
    {
        FinanceExplainer::fake(['Your sales went up.']);
        $ctx = $this->org();
        $explain = fn () => $this->actingAs($ctx['user'])
            ->postJson(route('domains.finance.explain', ['domain' => $ctx['domain']->name_slug]), ['topic' => 'overview']);

        $explain()->assertOk()->assertJsonPath('cached', false)->assertJsonPath('credits_remaining', 1);
        $explain()->assertOk()->assertJsonPath('cached', true)->assertJsonPath('credits_remaining', 1);

        $this->assertSame(['finance_explain'], AiUsage::query()->pluck('feature')->all());

        // A new explanation needs 2 credits; only 1 is left.
        $this->actingAs($ctx['user'])
            ->postJson(route('domains.finance.explain', ['domain' => $ctx['domain']->name_slug]), ['topic' => 'cash_flow'])
            ->assertStatus(402)
            ->assertJsonPath('out_of_credits', true);
    }

    public function test_the_billing_page_shows_this_months_credits(): void
    {
        $ctx = $this->org(500, paid: true);
        $this->usage($ctx['domain'], 400);

        $this->actingAs($ctx['user'])
            ->get(route('domains.billing.servicing.index', ['domain' => $ctx['domain']->name_slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Billing/Gcash')
                ->where('aiCredits.allowance', 500)
                ->where('aiCredits.used', 400)
                ->where('aiCredits.remaining', 100)
                ->where('aiCredits.percent', 80)
                ->where('aiCredits.near_limit', true)
                ->where('aiCredits.by_feature.0.feature', 'assistant')
                ->etc());
    }

    public function test_the_exception_message_names_the_reset_date(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14));
        $ctx = $this->org();
        $this->usage($ctx['domain'], 3);

        try {
            app(AiCreditService::class)->assertHasCredits($ctx['domain'], 'assistant');
            $this->fail('expected the credits to be used up');
        } catch (OutOfAiCreditsException $e) {
            $this->assertStringContainsString('all 3 AI credits for October', $e->getMessage());
            $this->assertStringContainsString('reset on November 1', $e->getMessage());
        }
    }
}
