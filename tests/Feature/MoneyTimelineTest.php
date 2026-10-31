<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\ExpenseService;
use App\Support\Wallet\MoneyTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Money movement's "All money" view: the drawer ledger plus money recorded elsewhere, each peso once.
 */
class MoneyTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $otherStore;

    private ExpenseCategory $rent;

    private User $admin;

    private FinancialAccount $bdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create([
            'settings' => ['features' => ['finance' => true]],
            'name' => 'Timeline Org',
            'name_slug' => 'timeline-org-'.Str::lower(Str::random(8)),
        ]);
        $this->store = $this->location('Main', true);
        $this->otherStore = $this->location('Branch');
        ExpenseCategory::ensureDefaults($this->domain->name_slug);
        $this->rent = ExpenseCategory::query()->forDomain($this->domain->name_slug)->where('name', 'Rent')->firstOrFail();
        $this->admin = $this->user('admin', 2);
        $this->bdo = FinancialAccount::query()->create(['domain' => $this->domain->name_slug, 'name' => 'BDO savings', 'type' => 'bank', 'is_active' => true]);
    }

    private function location(string $name, bool $default = false): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $default,
        ]);
    }

    private function user(string $roleName, int $level, ?InventoryLocation $location = null): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $role->forceFill(['level' => $level])->save();
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => ($location ?? $this->store)->id]);
        $user->assignRole($role);
        foreach (['wallet.money-movement', 'wallet-cash-ledger.index'] as $routeName) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                ['name' => 'Test '.$routeName.' '.Str::uuid()],
            ));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    private function expense(array $overrides = []): Expense
    {
        return app(ExpenseService::class)->create(array_merge([
            'domain' => $this->domain->name_slug,
            'location_id' => $this->store->id,
            'expense_category_id' => $this->rent->id,
            'amount' => 1000,
            'expense_date' => today()->toDateString(),
            'description' => 'Rent',
            'payment_method' => 'cash_register',
            'user_id' => $this->admin->id,
        ], $overrides));
    }

    private function sale(array $overrides = []): Sale
    {
        return Sale::query()->create(array_merge([
            'domain' => $this->domain->name_slug,
            'user_id' => $this->admin->id,
            'location_id' => $this->store->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'total_amount' => 500, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 500,
            'transaction_date' => now()->setTime(10, 0),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function timeline(array $query = []): array
    {
        return MoneyTimeline::build(Request::create('/', 'GET', $query + ['per_page' => 100]), $this->domain, $this->store);
    }

    public function test_each_peso_shows_once_with_where_it_moved(): void
    {
        $this->expense(['description' => 'Drawer rent', 'amount' => 1000]);
        $this->expense(['description' => 'Bank rent', 'amount' => 8000, 'payment_method' => 'bank', 'financial_account_id' => $this->bdo->id]);
        $this->expense(['description' => 'Branch rent', 'amount' => 999, 'payment_method' => 'bank', 'location_id' => $this->otherStore->id]);
        $this->sale(['grand_total' => 500]);
        $this->sale(['grand_total' => 700, 'payment_status' => 'voided']);
        $split = $this->sale(['payment_method' => 'split', 'grand_total' => 300]);
        SalePayment::query()->create(['sale_id' => $split->id, 'method' => 'card', 'amount' => 200]);
        SalePayment::query()->create(['sale_id' => $split->id, 'method' => 'cash', 'amount' => 100]);
        $customer = Customer::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Juan', 'phone' => '09171234567']);
        CreditTransaction::query()->create([
            'customer_id' => $customer->id, 'transaction_type' => 'payment', 'amount' => 250, 'balance_before' => 250,
            'balance_after' => 0, 'payment_method' => 'cash', 'user_id' => $this->admin->id, 'domain' => $this->domain->name_slug,
        ]);

        $rows = collect($this->timeline()['rows']->items());

        // Paid from the drawer: only its ledger line.
        $drawerRent = $rows->where('label', 'Expense: Drawer rent');
        $this->assertCount(1, $drawerRent);
        $this->assertSame('drawer', $drawerRent->first()['where_type']);
        $this->assertCount(0, $rows->where('source', 'expense')->where('label', 'Drawer rent'));

        $bank = $rows->firstWhere('label', 'Bank rent');
        $this->assertSame(['account', 'BDO savings', 'out', 8000.0], [$bank['where_type'], $bank['where_name'], $bank['direction'], $bank['amount']]);

        $this->assertNull($rows->firstWhere('label', 'Branch rent'));
        $this->assertSame([500.0], $rows->where('source', 'sale')->pluck('amount')->values()->all());
        $this->assertSame([200.0], $rows->where('source', 'sale_part')->pluck('amount')->values()->all());

        $credit = $rows->firstWhere('source', 'credit_payment');
        $this->assertSame(['in', 250.0, 'cash', null], [$credit['direction'], $credit['amount'], $credit['where_type'], $credit['location_id']]);

        $this->assertEquals(['in' => 950.0, 'out' => 9000.0, 'net' => -8050.0], $this->timeline()['totals']);
    }

    public function test_filters_by_account_and_business_wide(): void
    {
        $this->expense(['description' => 'Bank rent', 'amount' => 8000, 'payment_method' => 'bank', 'financial_account_id' => $this->bdo->id]);
        $supplier = Supplier::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Acme']);
        $bill = SupplierBill::query()->create([
            'domain' => $this->domain->name_slug, 'supplier_id' => $supplier->id, 'bill_date' => today(),
            'bill_type' => 'inventory', 'amount' => 600, 'user_id' => $this->admin->id,
        ]);
        SupplierPayment::query()->create([
            'domain' => $this->domain->name_slug, 'supplier_bill_id' => $bill->id, 'payment_date' => today(),
            'amount' => 600, 'payment_method' => 'ewallet', 'user_id' => $this->admin->id,
        ]);

        $byAccount = $this->timeline(['where' => 'account:'.$this->bdo->id]);
        $this->assertSame(['Bank rent'], collect($byAccount['rows']->items())->pluck('label')->all());

        $this->assertSame(['ewallet'], collect($this->timeline(['where' => 'ewallet'])['rows']->items())->pluck('where_type')->all());
        // The supplier payment has no store: hidden without business-wide.
        $this->assertCount(0, $this->timeline(['where' => 'ewallet', 'business_wide' => 0])['rows']->items());
    }

    public function test_only_finance_users_for_their_store_get_the_view(): void
    {
        // All places on Cash in & out (it was Where money is → All money).
        $url = fn (InventoryLocation $l, array $q = []) => route('domains.wallet.cash-in-out', ['domain' => $this->domain->name_slug, 'location_id' => $l->id] + $q);

        $this->actingAs($this->admin)->get($url($this->store, ['view' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewAllMoney', true)->where('view', 'all')->has('allMoney.rows')->has('allMoney.totals'));

        // This store (the default): nothing extra is built.
        $this->actingAs($this->admin)->get($url($this->store))
            ->assertInertia(fn (Assert $page) => $page->where('view', 'store')->where('allMoney', null));

        $manager = $this->user('manager', 3, $this->store);
        $this->actingAs($manager)->get($url($this->store, ['view' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('canViewAllMoney', true)->has('allMoney.rows'));
        $this->actingAs($manager)->get($url($this->otherStore, ['view' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('canViewAllMoney', false)->where('allMoney', null));

        $cashier = $this->user('cashier', 4);
        $this->actingAs($cashier)->get($url($this->store, ['view' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('canViewAllMoney', false)->where('allMoney', null));
    }
}
