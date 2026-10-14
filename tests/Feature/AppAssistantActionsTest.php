<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Permission;
use App\Models\Product\Discount;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\Ai\AssistantTools;
use App\Services\Ai\GuideLibrary;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Changes the AI assistant makes for customers, discounts, stock, payment channels and Finance:
 * only offered to roles (and businesses) allowed to make them, saved only on Confirm, and saved
 * by the same code as the pages.
 */
class AppAssistantActionsTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->domain = Domain::query()->create([
            'name' => 'Actions Org',
            'name_slug' => 'actions-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => true, 'finance' => true]],
        ]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
    }

    /** A manager-level user of this business who may confirm changes and has these permissions. */
    private function user(array $permissions): User
    {
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        foreach (['assistant.actions.confirm', ...$permissions] as $routeName) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                ['name' => 'Test '.$routeName.' '.Str::uuid()],
            ));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    private function tools(User $user): AssistantTools
    {
        return new AssistantTools(app(GuideLibrary::class), $user, $this->domain->fresh(), $this->store);
    }

    /** Proposes through the AI's tool; returns the card, or fails with the tool's answer. */
    private function propose(User $user, string $action, array $input): array
    {
        $tools = $this->tools($user);
        $result = json_decode($tools->run('propose_'.$action, $input), true);
        $this->assertSame('awaiting_confirmation', $result['status'] ?? null, json_encode($result));

        return $tools->pendingActions()[0];
    }

    private function confirm(User $user, array $card)
    {
        return $this->actingAs($user)->postJson(route('domains.assistant.actions.confirm', [
            'domain' => $this->domain->name_slug, 'token' => $card['token'],
        ]));
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Juan Dela Cruz', 'phone' => '09171234567', ...$attributes,
        ]);
    }

    public function test_customers_are_added_and_edited_on_confirm(): void
    {
        $user = $this->user(['customers.store', 'customers.update']);

        $card = $this->propose($user, 'create_customer', ['name' => 'Maria Santos', 'phone' => '09181112222']);
        $this->assertFalse(Customer::query()->where('name', 'Maria Santos')->exists());
        $this->confirm($user, $card)->assertOk();
        $maria = Customer::query()->where('domain', $this->domain->name_slug)->where('name', 'Maria Santos')->sole();
        $this->assertSame('09181112222', $maria->phone);

        $card = $this->propose($user, 'update_customer', ['customer_id' => $maria->id, 'email' => 'maria@example.com']);
        $this->assertContains(['label' => 'Email', 'value' => 'maria@example.com'], $card['fields']);
        $this->confirm($user, $card)->assertOk();
        $maria->refresh();
        $this->assertSame('maria@example.com', $maria->email);
        $this->assertSame('09181112222', $maria->phone, 'unchanged fields are kept');
    }

    public function test_loyalty_points_are_added_and_deducted(): void
    {
        $user = $this->user(['loyalty.adjust-points']);
        $juan = $this->customer(['loyalty_points' => 100]);

        $card = $this->propose($user, 'adjust_loyalty_points', ['customer_id' => $juan->id, 'type' => 'add', 'points' => 50, 'reason' => 'Birthday bonus']);
        $this->assertContains(['label' => 'Points', 'value' => '100 → 150'], $card['fields']);
        $this->confirm($user, $card)->assertOk();
        $this->assertSame(150, (int) $juan->fresh()->loyalty_points);

        $this->confirm($user, $this->propose($user, 'adjust_loyalty_points', ['customer_id' => $juan->id, 'type' => 'deduct', 'points' => 30, 'reason' => 'Correction']))->assertOk();
        $this->assertSame(120, (int) $juan->fresh()->loyalty_points);
    }

    public function test_a_credit_payment_lowers_what_the_customer_owes(): void
    {
        $user = $this->user(['credits.transactions.store']);
        $juan = $this->customer(['credit_enabled' => true, 'credit_limit' => 5000, 'credit_balance' => 1200]);

        $tooMuch = json_decode($this->tools($user)->run('propose_record_credit_payment', ['customer_id' => $juan->id, 'amount' => 2000]), true);
        $this->assertStringContainsString('more than', $tooMuch['error']);

        $card = $this->propose($user, 'record_credit_payment', ['customer_id' => $juan->id, 'amount' => 500, 'payment_method' => 'e-wallet', 'reference_number' => '123456']);
        $this->assertContains(['label' => 'Balance owed', 'value' => '₱1,200.00 → ₱700.00'], $card['fields']);
        $this->confirm($user, $card)->assertOk();

        $this->assertEquals(700, (float) $juan->fresh()->credit_balance);
    }

    public function test_discounts_are_saved_for_this_business(): void
    {
        $user = $this->user(['products.discounts.store', 'products.discounts.update', 'products.discounts.index']);

        $rejected = json_decode($this->tools($user)->run('propose_create_discount', ['name' => 'Too much', 'type' => 'percentage', 'value' => 150, 'scope' => 'order']), true);
        $this->assertStringContainsString('100%', $rejected['error']);

        $this->confirm($user, $this->propose($user, 'create_discount', ['name' => 'Payday 10%', 'type' => 'percentage', 'value' => 10, 'scope' => 'order']))->assertOk();
        $discount = Discount::query()->where('name', 'Payday 10%')->sole();
        $this->assertSame($this->domain->name_slug, $discount->domain, 'saved under the business slug, as from the page');
        $this->assertEquals(10, (float) $discount->value);

        $card = $this->propose($user, 'update_discount', ['discount_id' => $discount->id, 'value' => 15]);
        $this->assertContains(['label' => 'Discount', 'value' => '10% → 15%'], $card['fields']);
        $this->confirm($user, $card)->assertOk();
        $this->assertEquals(15, (float) $discount->fresh()->value);
        $this->assertSame('order', $discount->fresh()->scope);
    }

    public function test_a_stock_adjustment_is_saved_as_a_draft_without_changing_stock(): void
    {
        $user = $this->user(['inventory.adjustments.store']);
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $burger = Product::factory()->create(['domain' => $this->domain->name_slug, 'category_id' => $category->id, 'name' => 'Burger']);
        ProductInventory::query()->create(['product_id' => $burger->id, 'location_id' => $this->store->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'quantity_available' => 10]);

        $card = $this->propose($user, 'create_stock_adjustment', ['reason' => 'damaged_goods', 'items' => [['product_id' => $burger->id, 'actual_quantity' => 7]]]);
        $this->assertContains(['label' => 'Burger', 'value' => '10 → 7 (-3)'], $card['fields']);
        $this->confirm($user, $card)->assertOk();

        $adjustment = StockAdjustment::query()->where('domain', $this->domain->name_slug)->sole();
        $this->assertSame('draft', $adjustment->status);
        $this->assertSame('damaged_goods', $adjustment->reason);
        $this->assertSame($this->store->id, (int) $adjustment->location_id);
        $this->assertEquals(10, ProductInventory::query()->where('product_id', $burger->id)->value('quantity_on_hand'), 'stock changes only after approval');
    }

    public function test_a_payment_channel_is_added_to_the_users_store(): void
    {
        $user = $this->user(['payment-card-types.store']);

        $this->confirm($user, $this->propose($user, 'create_payment_channel', ['name' => 'GCash', 'kind' => 'ewallet']))->assertOk();

        $channel = PaymentCardType::query()->where('domain', $this->domain->name_slug)->sole();
        $this->assertSame(['GCash', 'ewallet', $this->store->id], [$channel->name, $channel->kind, (int) $channel->location_id]);
    }

    public function test_finance_changes_need_the_finance_feature_even_on_confirm(): void
    {
        $user = $this->user(['expenses.store', 'expenses.index', 'finance.suppliers.store', 'finance.bills.store', 'finance.payables.index']);
        $utilities = ExpenseCategory::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Utilities', 'is_active' => true]);

        $names = array_map(fn ($tool) => $tool->name(), $this->tools($user)->tools());
        foreach (['propose_record_expense', 'propose_create_supplier', 'propose_record_supplier_bill', 'list_expense_categories', 'list_suppliers'] as $tool) {
            $this->assertContains($tool, $names);
        }

        $card = $this->propose($user, 'record_expense', ['expense_category_id' => $utilities->id, 'amount' => 500, 'description' => 'October electricity', 'payment_method' => 'cash_register']);
        $this->confirm($user, $card)->assertOk();
        $expense = Expense::query()->where('domain', $this->domain->name_slug)->sole();
        $this->assertEquals(500, (float) $expense->amount);
        $this->assertSame($this->store->id, (int) $expense->location_id);

        $this->confirm($user, $this->propose($user, 'create_supplier', ['name' => 'Magnolia', 'payment_terms_days' => 15]))->assertOk();
        $supplier = Supplier::query()->where('domain', $this->domain->name_slug)->sole();
        $card = $this->propose($user, 'record_supplier_bill', ['supplier_id' => $supplier->id, 'amount' => 3200, 'bill_type' => 'inventory', 'bill_date' => today()->toDateString()]);
        $this->assertContains(['label' => 'Due', 'value' => today()->addDays(15)->toDateString()], $card['fields']);
        $this->confirm($user, $card)->assertOk();
        $this->assertEquals(3200, (float) SupplierBill::query()->where('supplier_id', $supplier->id)->value('amount'));

        // Proposed while the business had Finance, confirmed after it was turned off: refused.
        $card = $this->propose($user, 'record_expense', ['expense_category_id' => $utilities->id, 'amount' => 100, 'description' => 'Water', 'payment_method' => 'cash_register']);
        $this->domain->update(['settings' => ['features' => ['ai' => true, 'finance' => false]]]);
        $this->confirm($user, $card)->assertForbidden();
        $this->assertSame(1, Expense::query()->where('domain', $this->domain->name_slug)->count());

        $names = array_map(fn ($tool) => $tool->name(), $this->tools($user)->tools());
        $this->assertNotContains('propose_record_expense', $names);
        $this->assertNotContains('list_expense_categories', $names);
    }

    public function test_only_managers_may_record_expenses(): void
    {
        $cashier = $this->user(['expenses.store']);
        $cashier->forceFill(['role_level' => 5])->save();
        $utilities = ExpenseCategory::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Utilities', 'is_active' => true]);

        $refused = json_decode($this->tools($cashier->fresh())->run('propose_record_expense', ['expense_category_id' => $utilities->id, 'amount' => 50, 'description' => 'Ice', 'payment_method' => 'cash_register']), true);

        $this->assertStringContainsString('managers', $refused['error']);

        // Nor is a cashier handed an expense form they couldn't send.
        $tools = $this->tools($cashier->fresh());
        $form = json_decode($tools->run('show_fill_in_form', ['action' => 'record_expense']), true);
        $this->assertStringContainsString('managers', $form['error']);
        $this->assertSame([], $tools->forms());
    }

    public function test_customer_expense_and_discount_have_fill_in_forms(): void
    {
        $user = $this->user([
            'customers.index', 'customers.store', 'expenses.index', 'expenses.store',
            'products.discounts.index', 'products.discounts.store',
        ]);
        ExpenseCategory::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Utilities', 'is_active' => true]);
        ExpenseCategory::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Old', 'is_active' => false]);
        ExpenseCategory::query()->create(['domain' => 'another-business', 'name' => 'Not Ours', 'is_active' => true]);

        $tools = $this->tools($user);
        foreach (['create_customer', 'record_expense', 'create_discount'] as $action) {
            $shown = json_decode($tools->run('show_fill_in_form', ['action' => $action]), true);
            $this->assertSame('form_shown', $shown['status'] ?? null, $action.': '.json_encode($shown));
            $this->assertTrue($shown['page_link_shown_to_user'], $action);
        }

        $forms = collect($tools->forms())->keyBy('action');
        $this->assertSame(
            "Name: \nPhone (optional): \nEmail (optional): \nAddress (optional): \nBirthday (YYYY-MM-DD; optional): ",
            $forms['create_customer']['text'],
        );
        $this->assertSame(
            "Category (one of: Utilities): \nAmount (₱): \nWhat it was for: \n"
            ."Paid with (one of: Cash from the register, Bank, E-wallet, Card, Other): \n"
            ."Date (YYYY-MM-DD; optional): \nPaid to (optional): \nReference no. (optional): ",
            $forms['record_expense']['text'],
        );
        $this->assertSame(
            "Name: \nType (percentage or amount): \nValue (% or ₱): \nApplies to (order or product): \n"
            ."Minimum order (₱; optional): \nStart date (YYYY-MM-DD; optional): \nEnd date (YYYY-MM-DD; optional): ",
            $forms['create_discount']['text'],
        );
        $this->assertSame(
            ['Add or edit a customer', 'Record an expense', 'Create a discount or promo'],
            array_column($tools->links(), 'title'),
        );
    }

    public function test_an_expense_may_name_its_category(): void
    {
        $user = $this->user(['expenses.store']);
        $utilities = ExpenseCategory::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Utilities', 'is_active' => true]);
        ExpenseCategory::query()->create(['domain' => 'another-business', 'name' => 'Rent', 'is_active' => true]);

        $card = $this->propose($user, 'record_expense', ['expense_category' => ' utilities ', 'amount' => 750, 'description' => 'Water bill', 'payment_method' => 'bank']);
        $this->confirm($user, $card)->assertOk();
        $this->assertSame($utilities->id, (int) Expense::query()->where('domain', $this->domain->name_slug)->sole()->expense_category_id);

        $refused = json_decode($this->tools($user)->run('propose_record_expense', ['expense_category' => 'Rent', 'amount' => 9000, 'description' => 'October rent', 'payment_method' => 'bank']), true);
        $this->assertSame('There is no expense category "Rent". The categories are: Utilities.', $refused['error']);
    }
}
