<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\OtherIncome;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\WalletCashDailyExpected;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Supplier bills, other income and the statements built on them (net profit, cash flow,
 * balance sheet). Expenses themselves are covered by ExpenseTest.
 */
class FinanceRecordsTest extends TestCase
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

    /** @return array{domain: Domain, location: InventoryLocation, user: User, product: Product} */
    private function seedDomainContext(string $name = 'Records Org'): array
    {
        $domain = Domain::query()->create([
            'name' => $name,
            'name_slug' => 'records-org-'.Str::lower(Str::random(8)),
        ]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $location->id,
        ]);
        $category = Category::factory()->create(['domain' => $domain->name_slug]);
        $product = Product::factory()->create(['domain' => $domain->name_slug, 'category_id' => $category->id, 'cost' => 30]);

        return compact('domain', 'location', 'user', 'product');
    }

    private function url(array $ctx, string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $ctx['domain']->name_slug] + $params);
    }

    private function category(array $ctx, string $name, string $type = 'operating'): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['domain' => $ctx['domain']->name_slug, 'name' => $name],
            ['type' => $type, 'is_active' => true],
        );
    }

    private function expense(array $ctx, string $category, float $amount, string $type = 'operating'): Expense
    {
        return Expense::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'expense_category_id' => $this->category($ctx, $category, $type)->id,
            'amount' => $amount,
            'expense_date' => today(),
            'description' => $category,
            'payment_method' => 'other',
            'user_id' => $ctx['user']->id,
        ]);
    }

    /** A paid cash sale of `$quantity` × ₱40 (cost ₱30 each), no VAT. */
    private function sell(array $ctx, int $quantity, array $overrides = []): Sale
    {
        $total = 40 * $quantity;
        $sale = Sale::query()->create(array_merge([
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
            'transaction_date' => now()->setTime(10, 0),
        ], $overrides));
        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $ctx['product']->id, 'quantity' => $quantity, 'unit_price' => 40]);

        return $sale;
    }

    private function supplier(array $ctx, string $name = 'Acme Trading', int $terms = 15): Supplier
    {
        return Supplier::query()->create(['domain' => $ctx['domain']->name_slug, 'name' => $name, 'payment_terms_days' => $terms]);
    }

    private function bill(array $ctx, Supplier $supplier, float $amount, array $overrides = []): SupplierBill
    {
        return SupplierBill::query()->create(array_merge([
            'domain' => $ctx['domain']->name_slug,
            'supplier_id' => $supplier->id,
            'bill_date' => today(),
            'due_date' => today()->addDays(10),
            'bill_type' => 'inventory',
            'amount' => $amount,
            'user_id' => $ctx['user']->id,
        ], $overrides));
    }

    public function test_a_bill_is_paid_in_parts_and_cannot_be_overpaid(): void
    {
        $ctx = $this->seedDomainContext();
        $supplier = $this->supplier($ctx, terms: 15);

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.bills.store'), [
                'supplier_id' => $supplier->id,
                'bill_number' => 'SI-1001',
                'bill_date' => today()->toDateString(),
                'bill_type' => 'inventory',
                'amount' => 1000,
            ])
            ->assertSessionHasNoErrors();

        $bill = SupplierBill::query()->sole();
        // No due date given: the supplier's 15-day terms.
        $this->assertSame(today()->addDays(15)->toDateString(), $bill->due_date->toDateString());
        $this->assertSame('unpaid', $bill->status);

        $pay = fn (float $amount, array $more = []) => $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.bill-payments.store', ['bill' => $bill->id]), $more + [
                'payment_date' => today()->toDateString(),
                'amount' => $amount,
                'payment_method' => 'bank',
            ]);

        $pay(400)->assertSessionHasNoErrors();
        $this->assertSame('partial', $bill->fresh()->status);
        $this->assertEquals(600, $bill->fresh()->remaining);

        $pay(700)->assertSessionHasErrors('amount');
        $pay(100, ['payment_method' => 'cash_register'])->assertSessionHasErrors('location_id');

        $pay(600, ['payment_method' => 'cash_register', 'location_id' => $ctx['location']->id])->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertNotNull($bill->fresh()->paid_at);

        $cashPayment = SupplierPayment::query()->where('payment_method', 'cash_register')->sole();
        $line = WalletCashMovement::query()->findOrFail($cashPayment->wallet_cash_movement_id);
        $this->assertSame('supplier_payment', $line->kind);
        $this->assertSame('out', $line->direction);
        $this->assertEquals(600, $line->amount);

        // Paid bills can't be deleted until their payments are.
        $this->actingAs($ctx['user'])->delete($this->url($ctx, 'finance.bills.destroy', ['bill' => $bill->id]))->assertSessionHasErrors('bill');

        $this->actingAs($ctx['user'])
            ->delete($this->url($ctx, 'finance.bill-payments.destroy', ['payment' => $cashPayment->id]))
            ->assertSessionHasNoErrors();

        $this->assertEquals(600, $bill->fresh()->remaining);
        $this->assertSame(0, WalletCashMovement::query()->where('kind', 'supplier_payment')->count());
    }

    public function test_other_income_into_the_cash_register_raises_the_expected_drawer_cash(): void
    {
        $ctx = $this->seedDomainContext();
        $today = today()->toDateString();
        $recon = WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $today,
            'opening_cash' => 1000,
        ]);

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.other-income.store'), [
                'income_date' => $today,
                'amount' => 150,
                'description' => 'Stall rent',
                'payment_method' => 'cash_register',
                'location_id' => $ctx['location']->id,
            ])
            ->assertSessionHasNoErrors();

        $income = OtherIncome::query()->sole();
        $this->assertSame('in', $income->walletCashMovement->direction);
        $this->assertEquals(1150, WalletCashDailyExpected::compute($ctx['domain']->name_slug, $ctx['location']->id, $today, $recon)['expected_cash']);

        // Received by bank after all: the drawer line goes away.
        $this->actingAs($ctx['user'])
            ->put($this->url($ctx, 'finance.other-income.update', ['otherIncome' => $income->id]), [
                'income_date' => $today,
                'amount' => 150,
                'description' => 'Stall rent',
                'payment_method' => 'bank',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($income->fresh()->wallet_cash_movement_id);
        $this->assertSame(0, WalletCashMovement::query()->where('kind', 'other_income')->count());
    }

    public function test_a_closed_shift_refuses_cash_register_payments(): void
    {
        $ctx = $this->seedDomainContext();
        $yesterday = today()->subDay()->toDateString();
        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $yesterday,
            'opening_cash' => 500,
            'is_closed' => true,
        ]);
        $bill = $this->bill($ctx, $this->supplier($ctx), 300);

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.bill-payments.store', ['bill' => $bill->id]), [
                'payment_date' => $yesterday,
                'amount' => 100,
                'payment_method' => 'cash_register',
                'location_id' => $ctx['location']->id,
            ])
            ->assertSessionHasErrors('payment_date');

        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_income_statement_reaches_net_profit(): void
    {
        $ctx = $this->seedDomainContext();
        $this->sell($ctx, 10); // revenue 400, cost 300, gross profit 100

        $this->expense($ctx, 'Rent', 50);
        $this->expense($ctx, 'Interest', 5, 'other');
        // Electricity billed by a supplier counts on the bill date; stock bought does not count at all.
        $supplier = $this->supplier($ctx);
        $this->bill($ctx, $supplier, 20, ['bill_type' => 'expense', 'expense_category_id' => $this->category($ctx, 'Utilities')->id]);
        $this->bill($ctx, $supplier, 999);
        OtherIncome::query()->create([
            'domain' => $ctx['domain']->name_slug, 'income_date' => today(), 'amount' => 15, 'description' => 'Stall rent',
            'payment_method' => 'other', 'user_id' => $ctx['user']->id,
        ]);

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'profit-loss.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/ProfitLoss', false)
                ->where('current.gross_profit', 100)
                ->where('current.expenses', 70)
                ->where('current.operating_profit', 30)
                ->where('current.other_income', 15)
                ->where('current.other_expenses', 5)
                ->where('current.net_profit', 40)
                ->where('current.expense_lines.0.label', 'Rent')
                ->where('rows', fn ($rows) => collect($rows)->pluck('key')->contains('operating_profit'))
            );

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.dashboard', ['period' => 'today']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.current.net_profit', 40)
                ->where('overview.current.operating_expenses', 70)
                ->where('overview.expenses_recorded', true)
            );
    }

    public function test_the_income_statement_address_forwards_to_profit_and_loss(): void
    {
        $ctx = $this->seedDomainContext();

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.income-statement', ['period' => 'year']))
            ->assertRedirect($this->url($ctx, 'profit-loss.index', ['preset' => 'this_year']));

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.income-statement', ['period' => 'custom', 'start_date' => '2026-09-01', 'end_date' => '2026-09-15']))
            ->assertRedirect($this->url($ctx, 'profit-loss.index', ['preset' => 'custom', 'start_date' => '2026-09-01', 'end_date' => '2026-09-15']));
    }

    public function test_cash_flow_explains_the_gap_between_profit_and_money(): void
    {
        $ctx = $this->seedDomainContext();
        $slug = $ctx['domain']->name_slug;
        $this->sell($ctx, 10);                                              // ₱400 cash in
        $this->sell($ctx, 5, ['payment_method' => 'credit']);               // ₱200 on credit, not collected
        $this->expense($ctx, 'Rent', 50);

        $stockBill = $this->bill($ctx, $this->supplier($ctx), 300);
        SupplierPayment::query()->create([
            'domain' => $slug, 'supplier_bill_id' => $stockBill->id, 'payment_date' => today(),
            'amount' => 120, 'payment_method' => 'bank', 'user_id' => $ctx['user']->id,
        ]);
        $stockBill->refreshPaid();

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.cash-flow', ['period' => 'today']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('Finance/CashFlow', false)
                    ->where('current.in.customer_payments', 400)
                    ->where('current.out.stock_purchases', 120)
                    ->where('current.out.operating_expenses', 50)
                    ->where('current.net_change', 230)
                    // Gross profit 600 − 450 = 150, less rent 50.
                    ->where('current.net_profit', 100);

                // Net profit plus the bridge lands exactly on the change in money.
                $current = $page->toArray()['props']['current'];
                $bridged = $current['net_profit'] + array_sum(array_column($current['bridge'], 'amount'));
                $this->assertEqualsWithDelta($current['net_change'], $bridged, 0.001);
                $this->assertNotContains('other', array_column($current['bridge'], 'key'));
            });
    }

    public function test_balance_sheet_and_payables_summaries(): void
    {
        $ctx = $this->seedDomainContext();
        $supplier = $this->supplier($ctx, 'Late Supplies Co');
        $this->bill($ctx, $supplier, 800, ['bill_date' => today()->subDays(40), 'due_date' => today()->subDays(10)]);
        $this->bill($ctx, $supplier, 200, ['due_date' => today()->addDays(3)]);

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.payables.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Payables', false)
                ->where('payables.outstanding', 1000)
                ->where('payables.overdue', 800)
                ->where('payables.due_within_7_days', 200)
                ->where('payables.overdue_bills.0.days_overdue', 10)
                ->where('payables.largest.0.name', 'Late Supplies Co')
                ->has('bills.data', 2)
                ->where('suppliers.0.balance', 1000)
            );

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.balance-sheet'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/BalanceSheet', false)
                // No stock, drawer cash or customer credit yet: owing ₱1,000 makes it worth −₱1,000.
                ->where('balanceSheet.total_assets', 0)
                ->where('balanceSheet.total_liabilities', 1000)
                ->where('balanceSheet.net_worth', -1000)
            );
    }

    public function test_expense_categories_take_a_type(): void
    {
        $ctx = $this->seedDomainContext();

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'expenses.categories.store'), ['name' => 'Loan interest', 'type' => 'other'])
            ->assertSessionHasNoErrors();

        $category = ExpenseCategory::query()->where('name', 'Loan interest')->sole();
        $this->assertSame('other', $category->type);

        $this->actingAs($ctx['user'])
            ->put($this->url($ctx, 'expenses.categories.update', ['category' => $category->id]), ['type' => 'operating'])
            ->assertSessionHasNoErrors();
        $this->assertSame('operating', $category->fresh()->type);

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'expenses.categories.store'), ['name' => 'Bad', 'type' => 'capital'])
            ->assertSessionHasErrors('type');
    }

    public function test_records_of_another_business_are_out_of_reach(): void
    {
        $ctx = $this->seedDomainContext();
        $other = $this->seedDomainContext('Other Org');
        $theirs = OtherIncome::query()->create([
            'domain' => $other['domain']->name_slug, 'income_date' => today(), 'amount' => 10, 'description' => 'Theirs',
            'payment_method' => 'other', 'user_id' => $other['user']->id,
        ]);
        $theirBill = $this->bill($other, $this->supplier($other), 50);

        $this->actingAs($ctx['user'])
            ->delete($this->url($ctx, 'finance.other-income.destroy', ['otherIncome' => $theirs->id]))
            ->assertNotFound();
        $this->actingAs($ctx['user'])
            ->delete($this->url($ctx, 'finance.bills.destroy', ['bill' => $theirBill->id]))
            ->assertNotFound();

        $this->assertNotNull($theirs->fresh());
        $this->assertNotNull($theirBill->fresh());
    }
}
