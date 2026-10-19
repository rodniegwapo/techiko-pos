<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Models\WalletCashMovement;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Expenses and supplier bills paid by bank transfer or e-wallet record which account the money
 * left. The account's entered balance is left alone.
 */
class PaidFromAccountTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private ExpenseCategory $rent;

    private User $admin;

    private FinancialAccount $bdo;

    private FinancialAccount $gcash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
            ValidateCsrfToken::class,
        ]);

        $this->domain = Domain::query()->create([
            'settings' => ['features' => ['finance' => true, 'ai' => true]],
            'name' => 'Account Org',
            'name_slug' => 'account-org-'.Str::lower(Str::random(8)),
        ]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        ExpenseCategory::ensureDefaults($this->domain->name_slug);
        $this->rent = ExpenseCategory::query()->forDomain($this->domain->name_slug)->where('name', 'Rent')->firstOrFail();

        $role = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->forceFill(['level' => 2])->save();
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false]);
        $this->admin->assignRole($role);

        $this->bdo = $this->account('BDO savings', 'bank');
        $this->gcash = $this->account('GCash', 'ewallet');
    }

    private function account(string $name, string $type, ?Domain $domain = null, bool $active = true): FinancialAccount
    {
        return FinancialAccount::query()->create([
            'domain' => ($domain ?? $this->domain)->name_slug,
            'name' => $name,
            'type' => $type,
            'is_active' => $active,
        ]);
    }

    private function route(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug] + $params);
    }

    private function expensePayload(array $overrides = []): array
    {
        return array_merge([
            'expense_category_id' => $this->rent->id,
            'amount' => 1500,
            'expense_date' => now()->toDateString(),
            'description' => 'October rent',
            'payment_method' => 'bank',
            'financial_account_id' => $this->bdo->id,
            'location_id' => $this->store->id,
        ], $overrides);
    }

    private function bill(float $amount): SupplierBill
    {
        $supplier = Supplier::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Acme', 'payment_terms_days' => 15]);

        return SupplierBill::query()->create([
            'domain' => $this->domain->name_slug,
            'supplier_id' => $supplier->id,
            'bill_date' => today(),
            'due_date' => today()->addDays(10),
            'bill_type' => 'inventory',
            'amount' => $amount,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_an_expense_paid_by_bank_keeps_the_account_and_switching_to_cash_clears_it(): void
    {
        $this->actingAs($this->admin)->post($this->route('expenses.store'), $this->expensePayload())->assertSessionHasNoErrors();

        $expense = Expense::query()->sole();
        $this->assertSame($this->bdo->id, $expense->financial_account_id);
        $this->assertNull($expense->wallet_cash_movement_id);

        $this->actingAs($this->admin)
            ->get($this->route('expenses.index', ['account_id' => $this->bdo->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.account_name', 'BDO savings')
                ->has('options.accounts', 2)
            );

        $this->actingAs($this->admin)
            ->put($this->route('expenses.update', ['expense' => $expense->id]), $this->expensePayload(['payment_method' => 'cash_register']))
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertNull($expense->financial_account_id);
        $this->assertNotNull($expense->wallet_cash_movement_id);
    }

    public function test_an_account_from_another_business_or_an_inactive_one_is_refused(): void
    {
        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(8))]);
        $foreign = $this->account('Their bank', 'bank', $other);
        $closed = $this->account('Old bank', 'bank', active: false);

        foreach ([$foreign, $closed] as $account) {
            $this->actingAs($this->admin)
                ->post($this->route('expenses.store'), $this->expensePayload(['financial_account_id' => $account->id]))
                ->assertSessionHasErrors('financial_account_id');
        }
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_supplier_payment_by_ewallet_keeps_the_account_without_a_drawer_line(): void
    {
        $bill = $this->bill(1000);

        $this->actingAs($this->admin)
            ->post($this->route('finance.bill-payments.store', ['bill' => $bill->id]), [
                'payment_date' => today()->toDateString(),
                'amount' => 400,
                'payment_method' => 'ewallet',
                'financial_account_id' => $this->gcash->id,
            ])
            ->assertSessionHasNoErrors();

        $payment = SupplierPayment::query()->sole();
        $this->assertSame($this->gcash->id, $payment->financial_account_id);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_a_recurring_expense_passes_its_account_to_the_expenses_it_books(): void
    {
        $this->actingAs($this->admin)->post($this->route('expenses.recurring.store'), [
            'expense_category_id' => $this->rent->id,
            'amount' => 8000,
            'description' => 'Shop rent',
            'payment_method' => 'bank',
            'financial_account_id' => $this->bdo->id,
            'location_id' => $this->store->id,
            'frequency' => 'monthly',
            'day_of_month' => today()->day,
            'start_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->bdo->id, RecurringExpense::query()->sole()->financial_account_id);
        $this->assertSame($this->bdo->id, Expense::query()->sole()->financial_account_id);
    }

    public function test_accounts_show_what_was_paid_out_since_the_last_balance_without_changing_it(): void
    {
        $this->bdo->balances()->create(['as_of_date' => today()->subDays(5)->toDateString(), 'balance' => 50000, 'user_id' => $this->admin->id]);

        // Before the balance was entered: already in it, so not counted.
        $this->actingAs($this->admin)->post($this->route('expenses.store'), $this->expensePayload([
            'amount' => 999, 'expense_date' => today()->subDays(6)->toDateString(),
        ]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($this->route('expenses.store'), $this->expensePayload(['amount' => 1500]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post($this->route('finance.bill-payments.store', ['bill' => $this->bill(2000)->id]), [
                'payment_date' => today()->toDateString(),
                'amount' => 500,
                'payment_method' => 'bank',
                'financial_account_id' => $this->bdo->id,
            ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get($this->route('finance.balance-items.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.0.name', 'BDO savings')
                ->where('accounts.0.balance', 50000)
                ->where('accounts.0.paid_out_since', 2000)
                ->where('accounts.1.paid_out_since', 0)
            );
    }
}
