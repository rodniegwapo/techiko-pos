<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\MoneyTimeline;
use App\Support\Wallet\WalletCashDailyExpected;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A credit (utang) payment taken in cash goes into the store's drawer: a credit_payment cash-in on
 * its wallet ledger, so the expected drawer cash includes it. Other methods only record the store.
 */
class CreditPaymentWalletTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);

        $this->domain = Domain::query()->create(['name' => 'Utang Org', 'name_slug' => 'utang-org-'.Str::lower(Str::random(8))]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $this->user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $this->customer = Customer::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Juan Dela Cruz',
            'email' => 'juan-'.Str::lower(Str::random(6)).'@example.test',
            'credit_enabled' => true, 'credit_limit' => 10000, 'credit_balance' => 0, 'credit_terms_days' => 30,
            'tier' => 'bronze', 'loyalty_points' => 0, 'lifetime_spent' => 0,
        ]);
        // ₱1,000 owed.
        $this->send(['transaction_type' => 'credit', 'amount' => 1000])->assertOk();
    }

    private function send(array $body)
    {
        return $this->actingAs($this->user)->postJson(
            route('domains.credits.transactions.store', ['domain' => $this->domain->name_slug, 'customer' => $this->customer->id]),
            $body,
        );
    }

    private function expectedCash(): float
    {
        return WalletCashDailyExpected::compute($this->domain->name_slug, $this->store->id, today()->toDateString())['expected_cash'];
    }

    public function test_a_cash_payment_goes_into_the_drawer_once(): void
    {
        $this->assertEquals(0, $this->expectedCash());

        $this->send(['transaction_type' => 'payment', 'amount' => 400, 'payment_method' => 'cash'])->assertOk();

        $payment = CreditTransaction::query()->where('transaction_type', 'payment')->sole();
        $this->assertSame($this->store->id, $payment->location_id);
        $line = WalletCashMovement::query()->findOrFail($payment->wallet_cash_movement_id);
        $this->assertSame(['in', 'credit_payment', 400.0, $this->store->id], [$line->direction, $line->kind, $line->amount, (int) $line->location_id]);
        $this->assertEquals(400, $this->expectedCash());

        // All money shows it once: as the drawer line, not again as a credit payment.
        $rows = collect(MoneyTimeline::build(Request::create('/'), $this->domain, $this->store)['rows']->items());
        $this->assertCount(1, $rows);
        $this->assertSame(['ledger', 'drawer', 400.0], [$rows[0]['source'], $rows[0]['where_type'], $rows[0]['amount']]);
    }

    public function test_other_methods_record_the_store_but_leave_the_drawer_alone(): void
    {
        $this->send(['transaction_type' => 'payment', 'amount' => 300, 'payment_method' => 'e-wallet'])->assertOk();

        $payment = CreditTransaction::query()->where('transaction_type', 'payment')->sole();
        $this->assertSame($this->store->id, $payment->location_id);
        $this->assertNull($payment->wallet_cash_movement_id);
        $this->assertSame(0, WalletCashMovement::query()->count());
        $this->assertEquals(0, $this->expectedCash());

        $rows = collect(MoneyTimeline::build(Request::create('/', 'GET', ['business_wide' => 0]), $this->domain, $this->store)['rows']->items());
        $this->assertSame([['credit_payment', 'ewallet', $this->store->id]], $rows->map(fn ($r) => [$r['source'], $r['where_type'], $r['location_id']])->all());
    }

    public function test_a_closed_shift_refuses_a_cash_payment(): void
    {
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'business_date' => today()->toDateString(),
            'opening_cash' => 0, 'is_closed' => true,
        ]);

        $this->send(['transaction_type' => 'payment', 'amount' => 400, 'payment_method' => 'cash'])->assertStatus(422);

        $this->assertSame(0, CreditTransaction::query()->where('transaction_type', 'payment')->count());
        $this->assertEquals(1000, (float) $this->customer->fresh()->credit_balance);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }
}
