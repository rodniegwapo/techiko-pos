<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\FixedAsset;
use App\Models\Finance\Loan;
use App\Models\Finance\LoanPayment;
use App\Models\Finance\MonthlyReview;
use App\Models\Finance\OwnerInvestment;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Services\Ai\FinanceTools;
use App\Services\Ai\FinancialAssistant;
use App\Services\Finance\FinancialInsightService;
use App\Services\Finance\FinancialReportService;
use App\Support\Wallet\WalletCashBridgeExpected;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Bank and e-wallet balances, loans, equipment, owner investments, the questions box and the
 * monthly business review.
 */
class FinancePhase3Test extends TestCase
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

    /** @return array{domain: Domain, location: InventoryLocation, user: User, product: Product, category: Category} */
    private function seedDomainContext(string $name = 'Phase3 Org'): array
    {
        $domain = Domain::query()->create(['name' => $name, 'name_slug' => 'phase3-org-'.Str::lower(Str::random(8))]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => true, 'location_id' => $location->id]);
        $category = Category::factory()->create(['domain' => $domain->name_slug, 'name' => 'Beverages']);
        $product = Product::factory()->create(['domain' => $domain->name_slug, 'category_id' => $category->id, 'cost' => 30]);

        return compact('domain', 'location', 'user', 'product', 'category');
    }

    private function url(array $ctx, string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $ctx['domain']->name_slug] + $params);
    }

    /** A paid cash sale of `$quantity` × ₱40 (cost ₱30 each), no VAT. */
    private function sell(array $ctx, int $quantity, ?Carbon $at = null): Sale
    {
        $total = 40 * $quantity;
        $sale = Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'location_id' => $ctx['location']->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'total_amount' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => $total,
            'transaction_date' => $at ?? now()->setTime(10, 0),
        ]);
        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $ctx['product']->id, 'quantity' => $quantity, 'unit_price' => 40]);

        return $sale;
    }

    public function test_bank_and_ewallet_balances_count_their_latest_entry(): void
    {
        $ctx = $this->seedDomainContext();

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.accounts.store'), [
                'name' => 'BDO savings', 'type' => 'bank', 'balance' => 50000, 'as_of_date' => today()->subDays(10)->toDateString(),
            ])->assertSessionHasNoErrors();
        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.accounts.store'), ['name' => 'GCash', 'type' => 'ewallet'])
            ->assertSessionHasNoErrors();

        $bank = FinancialAccount::query()->where('name', 'BDO savings')->sole();
        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.account-balances.store', ['account' => $bank->id]), [
                'as_of_date' => today()->toDateString(), 'balance' => 62000,
            ])->assertSessionHasNoErrors();

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.balance-sheet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balanceSheet.assets.1.key', 'bank')
                ->where('balanceSheet.assets.1.amount', 62000)
                ->where('balanceSheet.assets.2.amount', 0)
                ->where('balanceSheet.accounts_missing', ['GCash'])
            );

        // The cash flow shows the balance at the start and end of the period.
        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.cash-flow', ['period' => 'custom', 'start_date' => today()->subDays(5)->toDateString(), 'end_date' => today()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('current.account_balances.accounts.0.name', 'BDO savings')
                ->where('current.account_balances.accounts.0.opening', 50000)
                ->where('current.account_balances.accounts.0.balance', 62000)
            );
    }

    public function test_a_loan_repaid_with_interest_lowers_what_is_owed_and_books_the_interest(): void
    {
        $ctx = $this->seedDomainContext();

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.loans.store'), [
                'lender' => 'Landbank', 'received_date' => today()->toDateString(), 'principal' => 100000,
                'payment_method' => 'cash_register', 'location_id' => $ctx['location']->id,
            ])->assertSessionHasNoErrors();

        $loan = Loan::query()->sole();
        $this->assertSame('loan_received', $loan->walletCashMovement->kind);
        $this->assertSame('in', $loan->walletCashMovement->direction);

        $repay = fn (array $data) => $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.loan-payments.store', ['loan' => $loan->id]), $data + [
                'payment_date' => today()->toDateString(), 'payment_method' => 'cash_register', 'location_id' => $ctx['location']->id,
            ]);

        $repay(['principal' => 200000])->assertSessionHasErrors('principal');
        $repay(['principal' => 0, 'interest' => 0])->assertSessionHasErrors('principal');
        $repay(['principal' => 10000, 'interest' => 1500])->assertSessionHasNoErrors();

        $payment = LoanPayment::query()->sole();
        $this->assertEquals(90000, $loan->fresh()->balanceOn());
        // Principal and interest each leave the drawer, on their own ledger lines.
        $this->assertEquals(10000, $payment->walletCashMovement->amount);
        $interest = Expense::query()->findOrFail($payment->expense_id);
        $this->assertEquals(1500, $interest->amount);
        $this->assertSame('Loan interest', $interest->category->name);
        $this->assertSame('other', $interest->category->type);
        $this->assertNotNull($interest->wallet_cash_movement_id);

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'profit-loss.index'))
            ->assertInertia(fn (Assert $page) => $page->where('current.other_expenses', 1500));

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.balance-sheet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balanceSheet.liabilities.1.key', 'loans')
                ->where('balanceSheet.liabilities.1.amount', 90000)
            );

        // A loan with repayments can't be deleted; deleting the repayment takes its interest too.
        $this->actingAs($ctx['user'])->delete($this->url($ctx, 'finance.loans.destroy', ['loan' => $loan->id]))->assertSessionHasErrors('loan');
        $this->actingAs($ctx['user'])->delete($this->url($ctx, 'finance.loan-payments.destroy', ['payment' => $payment->id]))->assertSessionHasNoErrors();
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(1, WalletCashMovement::query()->count()); // only the loan received is left
        $this->assertEquals(100000, $loan->fresh()->balanceOn());
    }

    public function test_equipment_is_depreciated_over_its_useful_life(): void
    {
        $ctx = $this->seedDomainContext();
        $asset = FixedAsset::query()->create([
            'domain' => $ctx['domain']->name_slug, 'name' => 'Chest freezer', 'category' => 'equipment',
            'purchase_date' => '2025-01-01', 'cost' => 36500, 'useful_life_months' => 12,
            'payment_method' => 'bank', 'user_id' => $ctx['user']->id,
        ]);

        // 365 days of life → ₱100 a day.
        $this->assertEquals(3100, $asset->depreciationBetween(Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31')));
        $this->assertEquals(36500 - 3100, $asset->bookValueOn(Carbon::parse('2025-01-31')));
        $this->assertEquals(0, $asset->bookValueOn(Carbon::parse('2026-06-01')));
        $this->assertEquals(0, $asset->depreciationBetween(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31')));

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'profit-loss.index', ['preset' => 'custom', 'start_date' => '2025-02-01', 'end_date' => '2025-02-28']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('current.expenses', 2800)
                ->where('current.expense_lines.0.key', 'depreciation')
            );
    }

    public function test_cash_flow_covers_loans_investments_equipment_and_drawer_movements(): void
    {
        $ctx = $this->seedDomainContext();
        $slug = $ctx['domain']->name_slug;
        $uid = $ctx['user']->id;
        $this->sell($ctx, 10); // ₱400 in, gross profit 100

        Loan::query()->create(['domain' => $slug, 'lender' => 'Bank', 'received_date' => today(), 'principal' => 5000, 'payment_method' => 'bank', 'user_id' => $uid]);
        OwnerInvestment::query()->create(['domain' => $slug, 'investment_date' => today(), 'amount' => 2000, 'payment_method' => 'bank', 'user_id' => $uid]);
        FixedAsset::query()->create([
            'domain' => $slug, 'name' => 'Oven', 'category' => 'equipment', 'purchase_date' => today(), 'cost' => 3000,
            'useful_life_months' => 30, 'payment_method' => 'bank', 'user_id' => $uid,
        ]);
        $movement = fn (array $a) => WalletCashMovement::query()->create($a + [
            'domain' => $slug, 'location_id' => $ctx['location']->id, 'movement_date' => today(), 'user_id' => $uid,
        ]);
        $movement(['kind' => 'cash_sale_topup', 'direction' => 'in', 'amount' => 500]);
        $movement(['kind' => 'adjustment', 'direction' => 'out', 'amount' => 20, 'notes' => WalletCashBridgeExpected::NOTE_COUNTED_VARIANCE]);
        $movement(['kind' => 'adjustment', 'direction' => 'in', 'amount' => 999, 'notes' => WalletCashBridgeExpected::NOTE_OPENING]);
        $movement(['kind' => 'owner_draw', 'direction' => 'out', 'amount' => 300]);
        $movement(['kind' => 'owner_draw', 'direction' => 'out', 'amount' => 4000, 'notes' => WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT]);

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.cash-flow', ['period' => 'today']))
            ->assertInertia(function (Assert $page) {
                $page->where('current.in.loans_received', 5000)
                    ->where('current.in.owner_investments', 2000)
                    ->where('current.out.equipment_purchases', 3000)
                    // Only the hand-recorded withdrawal; the end-of-shift cash-out stays in the business.
                    ->where('current.out.owner_withdrawals', 300)
                    // Top-up and counted variance, not the book-only opening line.
                    ->where('current.other_movements', 480)
                    ->where('current.net_change', 4580); // 400 + 5000 + 2000 − 3000 − 300 + 480

                $current = $page->toArray()['props']['current'];
                $bridged = $current['net_profit'] + array_sum(array_column($current['bridge'], 'amount'));
                $this->assertEqualsWithDelta($current['net_change'], $bridged, 0.001);
                $this->assertNotContains('other', array_column($current['bridge'], 'key'));
            });
    }

    public function test_owner_equity_splits_into_contributions_profit_and_other_changes(): void
    {
        $ctx = $this->seedDomainContext();
        $slug = $ctx['domain']->name_slug;
        OwnerInvestment::query()->create(['domain' => $slug, 'investment_date' => today(), 'amount' => 10000, 'payment_method' => 'bank', 'user_id' => $ctx['user']->id]);
        foreach ([[2000, null], [5000, WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT]] as [$amount, $notes]) {
            WalletCashMovement::query()->create([
                'domain' => $slug, 'location_id' => $ctx['location']->id, 'kind' => 'owner_draw', 'direction' => 'out',
                'amount' => $amount, 'notes' => $notes, 'movement_date' => today(), 'user_id' => $ctx['user']->id,
            ]);
        }
        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.accounts.store'), ['name' => 'BPI', 'type' => 'bank', 'balance' => 30000])
            ->assertSessionHasNoErrors();

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.balance-sheet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balanceSheet.net_worth', 30000)
                ->where('balanceSheet.equity.0.amount', 8000)
                // No sales, so no accumulated profit: the bank balance entered is the rest.
                ->where('balanceSheet.equity.1.amount', 0)
                ->where('balanceSheet.equity.2.amount', 22000)
            );
    }

    public function test_slow_movers_lead_the_suggestions(): void
    {
        $ctx = $this->seedDomainContext();
        $category = $ctx['category'];
        $idle = Product::factory()->create(['domain' => $ctx['domain']->name_slug, 'category_id' => $category->id, 'cost' => 50, 'name' => 'Idle Item']);
        foreach ([[$idle, 40, 2000], [$ctx['product'], 5, 150]] as [$product, $qty, $value]) {
            ProductInventory::query()->create([
                'product_id' => $product->id, 'location_id' => $ctx['location']->id,
                'quantity_on_hand' => $qty, 'quantity_available' => $qty, 'average_cost' => $value / $qty, 'total_value' => $value,
            ]);
        }
        $this->sell($ctx, 10); // the regular product sells; 5 left lasts 15 days

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.dashboard', ['period' => 'month']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('overview.products.slow_movers', 1)
                ->where('overview.products.slow_movers.0.name', 'Idle Item')
                ->where('overview.products.slow_movers.0.days_of_stock', null)
                ->where('recommendations', fn ($recs) => collect($recs)->pluck('key')->contains('slow_movers'))
            );
    }

    public function test_finance_tools_answer_from_the_books_without_customer_names(): void
    {
        $ctx = $this->seedDomainContext();
        $this->sell($ctx, 10);
        Customer::query()->create([
            'domain' => $ctx['domain']->name_slug, 'name' => 'Secret Customer', 'email' => 'secret-'.Str::random(5).'@example.test',
            'credit_enabled' => true, 'credit_limit' => 5000, 'credit_balance' => 700, 'credit_terms_days' => 30,
        ]);

        $tools = new FinanceTools(app(FinancialReportService::class), app(FinancialInsightService::class), $ctx['domain']->name_slug, null);
        $this->assertCount(10, $tools->definitions());

        $statement = json_decode($tools->run('get_income_statement', ['start_date' => today()->toDateString(), 'end_date' => today()->toDateString()]), true);
        $this->assertEquals(400, $statement['current']['revenue']);
        $this->assertEquals(100, $statement['current']['gross_profit']);

        $credit = $tools->run('get_customer_credit', []);
        $this->assertStringNotContainsString('Secret Customer', $credit);
        $this->assertStringContainsString('Customer A', $credit);

        $trend = json_decode($tools->run('get_monthly_trend', ['months' => 3]), true);
        $this->assertCount(3, $trend);

        $this->assertArrayHasKey('error', json_decode($tools->run('nope', []), true));
    }

    public function test_questions_go_to_the_assistant_within_the_daily_limit(): void
    {
        config(['services.anthropic.daily_question_limit' => 2]);
        $ctx = $this->seedDomainContext();

        $this->mock(FinancialAssistant::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('answer')
                ->twice()
                ->withArgs(fn ($question, $history, $tools) => $question === 'How much profit did I make?' && $tools instanceof FinanceTools)
                ->andReturn('You made ₱100 gross profit today.');
        });

        $ask = fn () => $this->actingAs($ctx['user'])->postJson($this->url($ctx, 'finance.ask'), ['question' => 'How much profit did I make?']);

        $ask()->assertOk()->assertJsonPath('answer', 'You made ₱100 gross profit today.')->assertJsonPath('remaining', 1);
        $ask()->assertOk()->assertJsonPath('remaining', 0);
        $ask()->assertStatus(429);
    }

    public function test_monthly_review_compares_the_month_with_the_one_before(): void
    {
        $ctx = $this->seedDomainContext();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
        $before = $lastMonth->copy()->subMonthNoOverflow();
        $this->sell($ctx, 10, $before->copy()->addDays(3)->setTime(10, 0));   // ₱400
        $this->sell($ctx, 30, $lastMonth->copy()->addDays(3)->setTime(10, 0)); // ₱1,200

        $this->artisan('finance:monthly-review', ['--domain' => $ctx['domain']->name_slug])->assertSuccessful();

        $review = MonthlyReview::query()->sole();
        $this->assertSame($lastMonth->format('Y-m'), $review->month);
        $this->assertEquals(1200, $review->figures['sales']);
        $this->assertEquals(300, $review->figures['gross_profit']);
        $this->assertContains('Sales increased 200% compared with '.$before->format('F').'.', $review->went_well);
        $this->assertTrue(collect($review->went_well)->contains(fn ($l) => str_starts_with($l, 'Beverages sales performed strongly')));
        $this->assertContains('Record your expenses to see your real profit', $review->actions);
        $this->assertNull($review->ai_summary); // no AI key in tests

        // Running again leaves it alone; the dashboard flags it until it is read.
        $this->artisan('finance:monthly-review', ['--domain' => $ctx['domain']->name_slug])->expectsOutputToContain('already reviewed');
        $this->actingAs($ctx['user'])->get($this->url($ctx, 'finance.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('latestReview.month', $review->month)->where('latestReview.read_at', null));

        $this->actingAs($ctx['user'])->get($this->url($ctx, 'finance.reviews.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Finance/MonthlyReviews', false)->where('selected.month', $review->month));
        $this->assertNotNull($review->fresh()->read_at);

        // The month in progress can't be reviewed yet.
        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.reviews.generate'), ['month' => now()->format('Y-m')])
            ->assertSessionHasErrors('month');
    }
}
