<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\ManualPaymentRequest;
use App\Models\PaymongoCheckout;
use App\Models\Permission;
use App\Models\ServiceTier;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\PayMongo\PayMongoCheckoutFulfillmentService;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Subscription payments move the organization's next payment date, keeping its billing day. */
class SubscriptionPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        $this->travelTo('2026-10-15 09:00:00');
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::query()->create(['name' => 'Pay Org', 'name_slug' => 'pay-org-'.Str::lower(Str::random(8)), ...$attributes]);
    }

    private function tier(string $amount = '499.00'): ServiceTier
    {
        return ServiceTier::query()->create([
            'slug' => 'tier-'.Str::lower(Str::random(6)), 'name' => 'Basic servicing', 'amount' => $amount, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['domain' => null, 'is_super_user' => true]);
    }

    private function pay(Domain $domain, ServiceTier $tier, array $data = [])
    {
        return $this->actingAs($this->superAdmin())->post(route('domains.payments.store', $domain), [
            'service_tier_id' => $tier->id, 'months' => 1, 'amount' => 499, 'paid_on' => '2026-10-15',
            'reference_number' => 'BDO-'.Str::random(6), ...$data,
        ]);
    }

    public function test_a_payment_counts_from_the_due_date_and_keeps_the_billing_day(): void
    {
        $domain = $this->domain();
        $tier = $this->tier();

        // First payment, no due date yet: a month from the day paid. It also puts them on the plan.
        $this->pay($domain, $tier, ['bank_name' => 'BDO'])->assertSessionHasNoErrors();
        $domain->refresh();
        $this->assertSame('2026-11-15', $domain->subscription_ends_at->toDateString());
        $this->assertSame($tier->id, $domain->current_service_tier_id);
        $this->assertSame('2026-10-15', $domain->subscription_started_at->toDateString());

        // Paid late (after the due date): still counts from Nov 15, so the billing day stays the 15th.
        $this->travelTo('2026-11-20 09:00:00');
        $this->pay($domain, $tier, ['paid_on' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-15', $domain->fresh()->subscription_ends_at->toDateString());

        // Three months ahead.
        $this->pay($domain, $tier, ['paid_on' => '2026-11-20', 'months' => 3, 'amount' => 1497])->assertSessionHasNoErrors();
        $this->assertSame('2027-03-15', $domain->fresh()->subscription_ends_at->toDateString());
    }

    public function test_the_end_of_a_month_does_not_overflow(): void
    {
        $domain = $this->domain(['subscription_ends_at' => '2027-01-31']);
        $tier = $this->tier();

        $payment = app(SubscriptionPaymentService::class)->record($domain, [
            'service_tier_id' => $tier->id, 'amount' => 499, 'method' => 'bank_transfer', 'paid_on' => '2026-10-15', 'reference_number' => 'X1',
        ], null);

        $this->assertSame('2027-02-28', $payment->period_end->toDateString());
    }

    public function test_the_payment_form_is_checked(): void
    {
        $domain = $this->domain();
        $tier = $this->tier();

        $this->pay($domain, $tier, ['reference_number' => ''])->assertSessionHasErrors('reference_number');
        $this->pay($domain, $tier, ['paid_on' => '2026-10-16'])->assertSessionHasErrors('paid_on');
        $this->pay($domain, $tier, ['months' => 2])->assertSessionHasErrors('months');

        $this->pay($domain, $tier, ['reference_number' => 'REF-1'])->assertSessionHasNoErrors();
        $this->pay($this->domain(), $tier, ['reference_number' => 'REF-1'])->assertSessionHasErrors('reference_number');
        $this->assertSame(1, SubscriptionPayment::query()->count());
    }

    public function test_recording_a_payment_can_unlock_the_organization(): void
    {
        $domain = $this->domain(['locked_at' => now()]);

        $this->pay($domain, $this->tier(), ['unlock' => true])->assertSessionHasNoErrors();

        $this->assertFalse($domain->fresh()->isLocked());
    }

    public function test_only_the_latest_payment_is_voided_and_the_due_date_goes_back(): void
    {
        $domain = $this->domain();
        $tier = $this->tier();
        $admin = $this->superAdmin();
        $this->pay($domain, $tier);
        $this->pay($domain, $tier);
        [$first, $second] = SubscriptionPayment::query()->orderBy('id')->get()->all();
        $this->assertSame('2026-12-15', $domain->fresh()->subscription_ends_at->toDateString());

        $this->actingAs($admin)->post(route('domains.payments.void', [$domain, $first]))->assertSessionHasErrors('payment');

        $this->actingAs($admin)->post(route('domains.payments.void', [$domain, $second]))->assertSessionHasNoErrors();
        $this->assertNotNull($second->fresh()->voided_at);
        $this->assertSame('2026-11-15', $domain->fresh()->subscription_ends_at->toDateString());

        // Then the first one is the latest; voiding it clears the due date it set.
        $this->actingAs($admin)->post(route('domains.payments.void', [$domain, $first]))->assertSessionHasNoErrors();
        $this->assertNull($domain->fresh()->subscription_ends_at);
    }

    public function test_only_super_admins_record_or_void_payments(): void
    {
        $domain = $this->domain();
        $staff = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);

        $this->actingAs($staff)->postJson(route('domains.payments.store', $domain), [])->assertForbidden();
        $this->assertSame(0, SubscriptionPayment::query()->count());
    }

    public function test_an_approved_gcash_request_is_logged_as_a_payment(): void
    {
        $domain = $this->domain();
        $tier = $this->tier();
        $request = ManualPaymentRequest::query()->create([
            'domain' => $domain->name_slug, 'service_tier_id' => $tier->id, 'amount' => 499, 'gcash_reference' => 'GC-123',
            'status' => ManualPaymentRequest::STATUS_PENDING, 'submitted_by' => User::factory()->create(['domain' => $domain->name_slug])->id,
        ]);

        $this->actingAs($this->superAdmin())->post(route('billing.manual-payments.approve', $request))->assertRedirect();

        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame(['gcash', 'GC-123', $request->id], [$payment->method, $payment->reference_number, $payment->manual_payment_request_id]);
        $this->assertSame('2026-11-15', $domain->fresh()->subscription_ends_at->toDateString());
        $this->assertSame($tier->id, $domain->fresh()->current_service_tier_id);
    }

    public function test_a_paymongo_payment_is_logged_as_a_payment(): void
    {
        $domain = $this->domain();
        $tier = $this->tier();
        PaymongoCheckout::query()->create([
            'domain' => $domain->name_slug, 'service_tier_id' => $tier->id, 'amount_centavos' => 49900,
            'payment_intent_id' => 'pi_test_1', 'status' => PaymongoCheckout::STATUS_PENDING,
            'initiated_by' => User::factory()->create(['domain' => $domain->name_slug])->id,
        ]);

        $this->assertTrue(app(PayMongoCheckoutFulfillmentService::class)->fulfillIfPending('pi_test_1', 49900));

        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame(['paymongo', 'pi_test_1'], [$payment->method, $payment->reference_number]);
        $this->assertEquals(499, $payment->amount);
        $this->assertSame('2026-11-15', $domain->fresh()->subscription_ends_at->toDateString());
    }

    public function test_the_next_payment_and_history_are_shown(): void
    {
        $domain = $this->domain();
        $other = $this->domain();
        $tier = $this->tier();
        $this->pay($domain, $tier, ['reference_number' => 'MINE-1']);
        $this->pay($other, $tier, ['reference_number' => 'THEIRS-1']);

        $this->actingAs($this->superAdmin())->get(route('domains.show', $domain))->assertInertia(fn (Assert $page) => $page
            ->where('billing.next_payment.due_on', '2026-11-15')
            ->where('billing.next_payment.days_left', 31)
            ->has('billing.payments', 1)
            ->where('billing.payments.0.reference_number', 'MINE-1')
            ->etc());

        $admin = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false]);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => 'billing.servicing.index', 'guard_name' => 'web'], ['name' => 'Servicing']));
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->actingAs($admin->fresh())->get(route('domains.billing.servicing.index', $domain))->assertInertia(fn (Assert $page) => $page
            ->where('nextPayment.due_on', '2026-11-15')
            ->where('nextPayment.amount', 499)
            ->has('payments', 1)
            ->where('payments.0.reference_number', 'MINE-1')
            ->missing('payments.0.recorded_by')
            ->etc());
    }
}
