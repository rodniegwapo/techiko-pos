<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\PaymongoCheckout;
use App\Models\Permission;
use App\Models\ServiceTier;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\PayMongo\PayMongoCheckoutFulfillmentService;
use App\Services\PayMongo\PayMongoHttpClient;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Pay now" on the subscription on hold page: a PayMongo QR for the chosen plan and months; once
 * PayMongo says it's paid the organization unlocks, and a super admin confirms the money later.
 * PayMongo is always faked here (the environment's key may be live).
 */
class SubscriptionRenewalTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $paymongo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        $this->travelTo('2026-10-15 09:00:00');
        config(['paymongo.secret_key' => 'sk_test_fake']);
        $this->paymongo = Mockery::mock(PayMongoHttpClient::class);
        $this->app->instance(PayMongoHttpClient::class, $this->paymongo);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::query()->create(['name' => 'Renew Org', 'name_slug' => 'renew-org-'.Str::lower(Str::random(8)), ...$attributes]);
    }

    private function tier(): ServiceTier
    {
        return ServiceTier::query()->create([
            'slug' => 'premium-'.Str::lower(Str::random(4)), 'name' => 'Premium servicing', 'amount' => '1999.00', 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function member(Domain $domain, bool $billing = true): User
    {
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);
        if ($billing) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => 'billing.servicing.index', 'guard_name' => 'web'], ['name' => 'Servicing']));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    /** PayMongo answers the three calls that make a QR Ph code. */
    private function paymongoMakesAQr(string $intentId = 'pi_renew_1'): void
    {
        $this->paymongo->shouldReceive('post')->with('payment_intents', Mockery::any())->andReturn(['data' => ['id' => $intentId, 'attributes' => ['client_key' => 'ck']]]);
        $this->paymongo->shouldReceive('post')->with('payment_methods', Mockery::any())->andReturn(['data' => ['id' => 'pm_1']]);
        $this->paymongo->shouldReceive('post')->with("payment_intents/{$intentId}/attach", Mockery::any())
            ->andReturn(['data' => ['attributes' => ['status' => 'awaiting_next_action', 'next_action' => ['code' => ['image_url' => 'data:image/png;base64,QR']]]]]);
    }

    private function start(User $user, Domain $domain, ServiceTier $tier, int $months = 3)
    {
        return $this->actingAs($user)->postJson(route('domains.renew.start', $domain), ['service_tier_id' => $tier->id, 'months' => $months]);
    }

    public function test_a_locked_organizations_admin_pays_for_months_and_is_unlocked_once_paid(): void
    {
        $domain = $this->domain(['locked_at' => now(), 'subscription_ends_at' => '2026-10-10']);
        $tier = $this->tier();
        $admin = $this->member($domain);
        $this->paymongoMakesAQr();

        $this->start($admin, $domain, $tier)
            ->assertOk()
            ->assertJsonPath('qr_image_data_url', 'data:image/png;base64,QR')
            ->assertJsonPath('amount', 5997)
            ->assertJsonPath('months', 3);
        $checkout = PaymongoCheckout::query()->sole();
        $this->assertSame([599700, 3], [$checkout->amount_centavos, $checkout->months]);

        // Not paid yet: still locked.
        $this->paymongo->shouldReceive('get')->with('payment_intents/pi_renew_1')->once()->andReturn(['data' => ['attributes' => ['status' => 'awaiting_next_action', 'amount' => 599700]]]);
        $status = fn () => $this->actingAs($admin)->getJson(route('domains.renew.status', [$domain, 'payment_intent_id' => 'pi_renew_1']));
        $status()->assertOk()->assertJsonPath('paid', false);
        $this->assertTrue($domain->fresh()->isLocked());

        // Paid: unlocked, three months on from the old due date, waiting for the super admin's confirmation.
        $this->paymongo->shouldReceive('get')->with('payment_intents/pi_renew_1')->once()->andReturn(['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 599700]]]);
        $status()->assertOk()->assertJsonPath('paid', true)->assertJsonPath('next_due_on', '2027-01-10');

        $domain->refresh();
        $this->assertFalse($domain->isLocked());
        $this->assertSame('2027-01-10', $domain->subscription_ends_at->toDateString());
        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame(['paymongo', 3], [$payment->method, $payment->months]);
        $this->assertTrue($payment->needsConfirmation());
    }

    private function plan(string $slug, string $amount, int $order): ServiceTier
    {
        return ServiceTier::query()->create([
            'slug' => $slug.'-'.Str::lower(Str::random(4)), 'name' => Str::title($slug).' servicing', 'amount' => $amount,
            'sort_order' => $order, 'is_active' => true, 'shown_on_billing' => true,
        ]);
    }

    public function test_the_billing_page_shows_the_current_plan_and_which_plans_are_upgrades(): void
    {
        $basic = $this->plan('basic', '499.00', 1);
        $standard = $this->plan('standard', '999.00', 2);
        $domain = $this->domain(['current_service_tier_id' => $basic->id, 'subscription_ends_at' => '2026-11-15']);
        $admin = $this->member($domain);
        $relation = fn ($page, ServiceTier $tier) => collect($page->toArray()['props']['tiers'])->firstWhere('id', $tier->id)['relation'];

        $this->actingAs($admin)->get(route('domains.billing.servicing.index', $domain))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($basic, $standard, $relation) {
                $page->component('Billing/Gcash')
                    ->where('currentTier.id', $basic->id)
                    ->where('canPay', true)
                    ->where('paymentsReady', true)
                    ->where('subscription.location_count', 0)
                    ->has('subscription.max_locations')
                    ->etc();
                $this->assertSame('current', $relation($page, $basic));
                $this->assertSame('upgrade', $relation($page, $standard));
            });

        // On Free, every paid plan is an upgrade; without a PayMongo key, online payment isn't offered.
        config(['paymongo.secret_key' => '']);
        $free = $this->domain();
        $this->actingAs($this->member($free))->get(route('domains.billing.servicing.index', $free))
            ->assertInertia(function (Assert $page) use ($basic, $relation) {
                $page->where('currentTier', null)->where('paymentsReady', false)->etc();
                $this->assertSame('upgrade', $relation($page, $basic));
            });
    }

    public function test_upgrading_pays_with_a_qr_and_switches_the_plan_once_paid(): void
    {
        $basic = $this->plan('basic', '499.00', 1);
        $standard = $this->plan('standard', '999.00', 2);
        $domain = $this->domain(['current_service_tier_id' => $basic->id, 'subscription_ends_at' => '2026-11-15']);
        $admin = $this->member($domain);
        $this->paymongoMakesAQr('pi_upgrade_1');

        $this->start($admin, $domain, $standard, 1)->assertOk()->assertJsonPath('amount', 999)->assertJsonPath('tier_name', 'Standard servicing');
        $this->assertSame($basic->id, $domain->fresh()->current_service_tier_id, 'nothing changes until it is paid');

        $this->paymongo->shouldReceive('get')->with('payment_intents/pi_upgrade_1')->once()->andReturn(['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 99900]]]);
        $this->actingAs($admin)->getJson(route('domains.renew.status', [$domain, 'payment_intent_id' => 'pi_upgrade_1']))
            ->assertOk()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('next_due_on', '2026-12-15');

        $this->assertSame($standard->id, $domain->fresh()->current_service_tier_id);
    }

    public function test_the_webhook_also_unlocks(): void
    {
        $domain = $this->domain(['locked_at' => now()]);
        $tier = $this->tier();
        PaymongoCheckout::query()->create([
            'payment_intent_id' => 'pi_hook', 'domain' => $domain->name_slug, 'service_tier_id' => $tier->id,
            'amount_centavos' => 199900, 'months' => 1, 'status' => PaymongoCheckout::STATUS_PENDING,
        ]);

        $this->assertTrue(app(PayMongoCheckoutFulfillmentService::class)->fulfillIfPending('pi_hook', 199900));

        $this->assertFalse($domain->fresh()->isLocked());
    }

    public function test_only_its_admins_and_managers_can_pay(): void
    {
        $domain = $this->domain(['locked_at' => now()]);
        $tier = $this->tier();

        $this->start($this->member($domain, billing: false), $domain, $tier)->assertForbidden();
        $this->start($this->member($this->domain()), $domain, $tier)->assertForbidden();
        $this->start($this->member($domain), $domain, $tier, months: 2)->assertUnprocessable();
        $this->assertSame(0, PaymongoCheckout::query()->count());
    }

    public function test_without_a_paymongo_key_pay_now_is_hidden_and_refused(): void
    {
        config(['paymongo.secret_key' => '']);
        $domain = $this->domain(['locked_at' => now()]);
        $admin = $this->member($domain);

        $this->actingAs($admin)->get(route('domains.subscription-on-hold', $domain))->assertInertia(fn (Assert $page) => $page
            ->where('canPay', true)
            ->where('paymentsReady', false)
            ->etc());
        $this->start($admin, $domain, $this->tier())->assertUnprocessable()->assertJsonPath('message', "Online payment isn't set up yet. Please contact us to renew.");
    }

    public function test_the_on_hold_page_offers_the_plans_to_those_who_can_pay(): void
    {
        $domain = $this->domain(['locked_at' => now()]);
        $tier = $this->tier();

        $this->actingAs($this->member($domain))->get(route('domains.subscription-on-hold', $domain))->assertInertia(fn (Assert $page) => $page
            ->component('SubscriptionOnHold')
            ->where('canPay', true)
            ->where('paymentsReady', true)
            ->where('tiers.0.id', $tier->id)
            ->where('tiers.0.amount', 1999)
            ->etc());

        $this->actingAs($this->member($domain, billing: false))->get(route('domains.subscription-on-hold', $domain))
            ->assertInertia(fn (Assert $page) => $page->where('canPay', false)->etc());
    }

    public function test_a_super_admin_confirms_a_paymongo_payment_and_others_are_confirmed_when_recorded(): void
    {
        $domain = $this->domain();
        $tier = $this->tier();
        $service = app(SubscriptionPaymentService::class);
        $online = $service->record($domain, ['service_tier_id' => $tier->id, 'amount' => 1999, 'method' => 'paymongo', 'paid_on' => '2026-10-15', 'reference_number' => 'pi_x'], null);
        $bank = $service->record($domain, ['service_tier_id' => $tier->id, 'amount' => 1999, 'method' => 'bank_transfer', 'paid_on' => '2026-10-15', 'reference_number' => 'BDO-1'], null);
        $this->assertTrue($online->needsConfirmation());
        $this->assertNotNull($bank->confirmed_at);

        $url = route('domains.payments.confirm', [$domain, $online]);
        $this->actingAs($this->member($domain))->postJson($url)->assertForbidden();

        $admin = User::factory()->create(['domain' => null, 'is_super_user' => true]);
        $this->actingAs($admin)->get(route('domains.show', $domain))
            ->assertInertia(fn (Assert $page) => $page->where('billing.payments.1.awaiting_confirmation', true)->etc());

        $this->actingAs($admin)->post($url)->assertRedirect();
        $this->assertFalse($online->fresh()->needsConfirmation());
        $this->assertSame($admin->id, $online->fresh()->confirmed_by);
    }
}
