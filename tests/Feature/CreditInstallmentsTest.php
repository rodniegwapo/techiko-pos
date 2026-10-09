<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Charging a customer on credit by hand, due on a date or split into installments, and payments
 * that settle what is owed in part: the picked charge first, then the oldest, earliest installment first.
 */
class CreditInstallmentsTest extends TestCase
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

    /** @return array{domain: Domain, user: User, customer: Customer} */
    private function seedContext(float $limit = 10000): array
    {
        $domain = Domain::query()->create([
            'name' => 'Credit Org',
            'name_slug' => 'cri-org-'.Str::lower(Str::random(8)),
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
        ]);

        $customer = Customer::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Juan Dela Cruz',
            'email' => 'juan-'.Str::lower(Str::random(6)).'@example.test',
            'credit_enabled' => true,
            'credit_limit' => $limit,
            'credit_balance' => 0,
            'credit_terms_days' => 30,
            'tier' => 'bronze',
            'loyalty_points' => 0,
            'lifetime_spent' => 0,
        ]);

        return compact('domain', 'user', 'customer');
    }

    private function send(array $ctx, array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $ctx['user'])->postJson(
            route('domains.credits.transactions.store', [
                'domain' => $ctx['domain']->name_slug,
                'customer' => $ctx['customer']->id,
            ]),
            $body
        );
    }

    private function threeMonthly(): array
    {
        return [
            ['due_date' => today()->addMonth()->toDateString(), 'amount' => 1000],
            ['due_date' => today()->addMonths(2)->toDateString(), 'amount' => 1000],
            ['due_date' => today()->addMonths(3)->toDateString(), 'amount' => 1000],
        ];
    }

    public function test_a_manual_charge_raises_the_balance_and_is_due_on_its_date(): void
    {
        $ctx = $this->seedContext();
        $due = today()->addDays(10)->toDateString();

        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 1500, 'due_date' => $due])
            ->assertOk();

        $this->assertEquals(1500, (float) $ctx['customer']->fresh()->credit_balance);
        $charge = CreditTransaction::where('customer_id', $ctx['customer']->id)->sole();
        $this->assertSame('credit', $charge->transaction_type);
        $this->assertSame($due, $charge->due_date->toDateString());
        $this->assertCount(0, $charge->installments);
    }

    public function test_a_charge_in_installments_keeps_the_schedule_and_is_due_with_the_last(): void
    {
        $ctx = $this->seedContext();

        $this->send($ctx, [
            'transaction_type' => 'credit',
            'amount' => 3000,
            'installments' => $this->threeMonthly(),
        ])->assertOk();

        $charge = CreditTransaction::where('customer_id', $ctx['customer']->id)->sole();
        $this->assertSame([1, 2, 3], $charge->installments->pluck('seq')->all());
        $this->assertSame(today()->addMonths(3)->toDateString(), $charge->due_date->toDateString());
        $this->assertEquals(3000, (float) $ctx['customer']->fresh()->credit_balance);
    }

    public function test_installments_must_add_up_and_run_in_order(): void
    {
        $ctx = $this->seedContext();

        $short = $this->threeMonthly();
        $short[2]['amount'] = 900;
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 3000, 'installments' => $short])
            ->assertStatus(422)->assertJsonValidationErrors('installments');

        $backwards = array_reverse($this->threeMonthly());
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 3000, 'installments' => $backwards])
            ->assertStatus(422)->assertJsonValidationErrors('installments');

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_a_charge_cannot_go_over_the_credit_limit(): void
    {
        $ctx = $this->seedContext(limit: 1000);

        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 1500, 'due_date' => today()->addWeek()->toDateString()])
            ->assertStatus(422);

        $this->assertEquals(0, (float) $ctx['customer']->fresh()->credit_balance);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_only_who_may_set_credit_may_charge_it(): void
    {
        $ctx = $this->seedContext();
        $cashier = User::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'is_super_user' => false,
        ]);

        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 100, 'due_date' => today()->addWeek()->toDateString()], $cashier)
            ->assertForbidden();
    }

    public function test_a_part_payment_pays_the_earliest_installment_first(): void
    {
        $ctx = $this->seedContext();
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 3000, 'installments' => $this->threeMonthly()])->assertOk();
        $charge = CreditTransaction::where('transaction_type', 'credit')->sole();

        $this->send($ctx, [
            'transaction_type' => 'payment',
            'amount' => 1500,
            'payment_method' => 'e-wallet',
            'transaction_ids' => [$charge->id],
        ])->assertOk();

        $charge->refresh()->load('installments');
        [$first, $second, $third] = $charge->installments->all();

        $this->assertNotNull($first->paid_at);
        $this->assertEquals(500, (float) $second->paid_amount);
        $this->assertNull($second->paid_at);
        $this->assertEquals(0, (float) $third->paid_amount);

        $this->assertEquals(1500, (float) $charge->paid_amount);
        $this->assertNull($charge->paid_at, 'the charge is not paid until all of it is');
        $this->assertEquals(1500, $charge->remaining);
        $this->assertEquals(1500, (float) $ctx['customer']->fresh()->credit_balance);

        $payment = CreditTransaction::where('transaction_type', 'payment')->sole();
        $this->assertSame('e-wallet', $payment->payment_method);
    }

    public function test_paying_the_rest_settles_the_charge(): void
    {
        $ctx = $this->seedContext();
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 3000, 'installments' => $this->threeMonthly()])->assertOk();

        $this->send($ctx, ['transaction_type' => 'payment', 'amount' => 1000])->assertOk();
        $this->send($ctx, ['transaction_type' => 'payment', 'amount' => 2000])->assertOk();

        $charge = CreditTransaction::where('transaction_type', 'credit')->sole()->load('installments');
        $this->assertNotNull($charge->paid_at);
        $this->assertTrue($charge->installments->every(fn ($i) => $i->paid_at !== null));
        $this->assertEquals(0, (float) $ctx['customer']->fresh()->credit_balance);
    }

    public function test_a_payment_without_a_pick_goes_to_the_oldest_charge(): void
    {
        $ctx = $this->seedContext();
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 200, 'due_date' => today()->addDays(20)->toDateString()])->assertOk();
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 300, 'due_date' => today()->addDays(5)->toDateString()])->assertOk();

        $this->send($ctx, ['transaction_type' => 'payment', 'amount' => 300])->assertOk();

        $sooner = CreditTransaction::where('transaction_type', 'credit')->where('amount', 300)->sole();
        $later = CreditTransaction::where('transaction_type', 'credit')->where('amount', 200)->sole();
        $this->assertNotNull($sooner->paid_at, 'the one due first is paid first');
        $this->assertNull($later->paid_at);
        $this->assertEquals(0, (float) $later->paid_amount);
    }

    public function test_only_late_installments_count_as_overdue(): void
    {
        $ctx = $this->seedContext();
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 3000, 'installments' => $this->threeMonthly()])->assertOk();
        $charge = CreditTransaction::where('transaction_type', 'credit')->sole();

        // A month and a bit on: the first installment is late, the others are not yet due.
        $this->travelTo(today()->addMonth()->addDays(3));

        $customer = $ctx['customer']->fresh();
        $this->assertEquals(1000, $customer->getTotalOverdueAmount());
        $this->assertSame(3, $charge->fresh()->getDaysOverdue());

        $this->send($ctx, ['transaction_type' => 'payment', 'amount' => 400])->assertOk();
        $this->assertEquals(600, $ctx['customer']->fresh()->getTotalOverdueAmount());
    }

    public function test_an_adjustment_can_lower_the_balance(): void
    {
        $ctx = $this->seedContext();
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 1000, 'due_date' => today()->addWeek()->toDateString()])->assertOk();

        $this->send($ctx, ['transaction_type' => 'adjustment', 'amount' => -250])->assertOk();

        $this->assertEquals(750, (float) $ctx['customer']->fresh()->credit_balance);
    }

    public function test_the_credit_page_lists_charges_with_their_schedule(): void
    {
        $ctx = $this->seedContext();
        $this->send($ctx, ['transaction_type' => 'credit', 'amount' => 3000, 'installments' => $this->threeMonthly()])->assertOk();

        $this->actingAs($ctx['user'])
            ->getJson(route('domains.credits.outstanding-invoices', [
                'domain' => $ctx['domain']->name_slug,
                'customer' => $ctx['customer']->id,
            ]))
            ->assertOk()
            ->assertJsonCount(3, 'outstanding_invoices.0.installments')
            ->assertJsonPath('outstanding_invoices.0.remaining', 3000)
            ->assertJsonPath('outstanding_invoices.0.days_overdue', null);
    }
}
