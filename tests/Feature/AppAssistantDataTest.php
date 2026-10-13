<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\VoidLog;
use App\Services\Ai\AssistantTools;
use App\Services\Ai\GuideLibrary;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The AI assistant's answers about the business's own records: only for roles that may see them,
 * following the pages' rules (own sales only below manager, one store for store-bound staff),
 * and with the same figures the pages show.
 */
class AppAssistantDataTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $otherStore;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->domain = Domain::query()->create([
            'name' => 'Data Org',
            'name_slug' => 'data-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => true]],
        ]);
        $this->store = $this->makeStore('Main', true);
        $this->otherStore = $this->makeStore('Branch 2');
        $this->category = Category::factory()->create(['domain' => $this->domain->name_slug]);
    }

    private function makeStore(string $name, bool $default = false): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $default,
        ]);
    }

    /** A user of this business with these permissions; a role level of 5 makes them a cashier (own sales only). */
    private function user(array $permissions, ?int $roleLevel = null, ?InventoryLocation $store = null): User
    {
        $user = User::factory()->create([
            'domain' => $this->domain->name_slug,
            'is_super_user' => false,
            'location_id' => ($store ?? $this->store)->id,
        ]);
        if ($roleLevel !== null) {
            $role = Role::query()->firstOrCreate(['name' => 'level-'.$roleLevel, 'guard_name' => 'web']);
            $role->forceFill(['level' => $roleLevel])->save();
            $user->assignRole($role);
        }
        foreach ($permissions as $routeName) {
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
        return new AssistantTools(app(GuideLibrary::class), $user, $this->domain, $this->store);
    }

    private function ask(User $user, string $tool, array $input = []): array
    {
        return json_decode($this->tools($user)->run($tool, $input), true);
    }

    private function toolNames(User $user): array
    {
        return array_map(fn ($tool) => $tool->name(), $this->tools($user)->tools());
    }

    private function product(string $name, float $cost = 30): Product
    {
        return Product::factory()->create(['domain' => $this->domain->name_slug, 'category_id' => $this->category->id, 'name' => $name, 'cost' => $cost]);
    }

    private function sell(User $cashier, Product $product, int $quantity, string $status = 'paid', ?InventoryLocation $store = null): Sale
    {
        $total = 50 * $quantity;
        $sale = Sale::query()->create([
            'domain' => $this->domain->name_slug,
            'user_id' => $cashier->id,
            'location_id' => ($store ?? $this->store)->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_method' => 'cash',
            'payment_status' => $status,
            'total_amount' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => $total,
            'transaction_date' => now()->setTime(10, 0),
        ]);
        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 50]);

        return $sale;
    }

    private function stock(Product $product, InventoryLocation $store, float $available, ?float $lowLevel = null): void
    {
        ProductInventory::query()->create([
            'product_id' => $product->id, 'location_id' => $store->id,
            'quantity_on_hand' => $available, 'quantity_reserved' => 0, 'quantity_available' => $available,
            'location_reorder_level' => $lowLevel,
        ]);
    }

    public function test_data_tools_follow_permissions_and_features(): void
    {
        $cashier = $this->toolNames($this->user(['sales.index'], 5));
        $this->assertContains('list_discounts', $cashier);
        foreach (['sales_summary', 'find_customer', 'low_stock', 'overdue_credit', 'get_income_statement'] as $tool) {
            $this->assertNotContains($tool, $cashier);
        }
        $this->assertSame('not_allowed', $this->ask($this->user(['sales.index'], 5), 'sales_summary')['error']);

        $manager = $this->toolNames($this->user(['sales-history.index', 'inventory.index', 'inventory.products', 'customers.index', 'credits.index', 'voids.index']));
        foreach (['sales_summary', 'low_stock', 'stock_levels', 'find_customer', 'overdue_credit', 'voids', 'list_stores'] as $tool) {
            $this->assertContains($tool, $manager);
        }

        // Finance questions need the Finance permission and a business that has Finance.
        $owner = $this->user(['finance.ask']);
        $this->assertNotContains('get_income_statement', $this->toolNames($owner));
        $this->domain->update(['settings' => ['features' => ['ai' => true, 'finance' => true]]]);
        $this->assertContains('get_income_statement', $this->toolNames($owner));
        $this->assertContains('get_balance_sheet', $this->toolNames($owner));
    }

    public function test_sales_summary_matches_the_sales_history_page(): void
    {
        $manager = $this->user(['sales-history.index']);
        $cashier = $this->user(['sales-history.index'], 5);
        $chicken = $this->product('Chickenjoy', 30);
        $this->sell($cashier, $chicken, 2);                 // ₱100
        $this->sell($manager, $chicken, 3);                 // ₱150
        $this->sell($cashier, $chicken, 10, 'voided');      // not counted

        $page = $this->actingAs($manager)
            ->get(route('domains.sales-history.index', ['domain' => $this->domain->name_slug]))
            ->assertOk()
            ->viewData('page')['props']['summary'];

        $summary = $this->ask($manager, 'sales_summary');
        foreach (['sales_count', 'gross', 'net', 'vat', 'voided_sales', 'profit'] as $key) {
            $this->assertEquals($page[$key], $summary[$key], $key);
        }
        $this->assertSame(2, $summary['sales_count']);
        $this->assertEquals(250, $summary['net']);
        $this->assertEquals(100, $summary['profit']);        // ₱250 − 5 × ₱30
        $this->assertSame('Chickenjoy', $summary['top_products'][0]['product']);
        $this->assertSame('Main', $summary['store']);
    }

    public function test_cashiers_get_only_their_own_sales_and_no_profit(): void
    {
        $manager = $this->user(['sales-history.index']);
        $cashier = $this->user(['sales-history.index', 'sales-history.show'], 5);
        $chicken = $this->product('Chickenjoy');
        $own = $this->sell($cashier, $chicken, 2);
        $other = $this->sell($manager, $chicken, 3);

        $summary = $this->ask($cashier, 'sales_summary');
        $this->assertSame(1, $summary['sales_count']);
        $this->assertEquals(100, $summary['net']);
        $this->assertArrayNotHasKey('profit', $summary);
        $this->assertSame('only this user\'s own sales', $summary['includes']);

        $this->assertTrue($this->ask($cashier, 'find_sale', ['invoice' => $own->invoice_number])['found']);
        $this->assertFalse($this->ask($cashier, 'find_sale', ['invoice' => $other->invoice_number])['found']);
    }

    public function test_low_stock_uses_the_stores_level_else_the_products(): void
    {
        $manager = $this->user(['inventory.index']);
        $low = $this->product('Burger');
        $out = $this->product('Fries');
        $fine = $this->product('Spaghetti');
        $productLevel = $this->product('Sundae');
        $productLevel->update(['reorder_level' => 10]);
        $this->stock($low, $this->store, 2, 5);
        $this->stock($out, $this->store, 0);
        $this->stock($fine, $this->store, 50, 5);
        $this->stock($productLevel, $this->store, 8);
        $this->stock($low, $this->otherStore, 1, 5);          // another store: not in this answer

        $answer = $this->ask($manager, 'low_stock');

        $this->assertSame('Main', $answer['store']);
        $this->assertEqualsCanonicalizing(['Burger', 'Sundae'], array_column($answer['low_stock'], 'product'));
        $this->assertSame(['Fries'], array_column($answer['out_of_stock'], 'product'));

        // Only staff free to switch stores (admins, role level 2) can look at every store.
        $manager->forceFill(['role_level' => 2])->save();
        $everywhere = $this->ask($manager->fresh(), 'low_stock', ['store_id' => 'all']);
        $this->assertSame('all stores', $everywhere['store']);
        $this->assertSame(3, $everywhere['low_stock_count']);
    }

    public function test_store_bound_staff_only_see_their_own_store(): void
    {
        // role_level 3 (the default for staff) ties a user to their assigned store.
        $clerk = $this->user(['inventory.products']);
        $clerk->forceFill(['role_level' => 3])->save();
        $burger = $this->product('Burger');
        $this->stock($burger, $this->store, 7);
        $this->stock($burger, $this->otherStore, 40);

        $answer = $this->ask($clerk->fresh(), 'stock_levels', ['query' => 'Burger', 'store_id' => (string) $this->otherStore->id]);

        $this->assertSame('Main', $answer['store']);
        $this->assertEquals([7], array_column($answer['products'][0]['stores'], 'available'));
    }

    public function test_customer_credit_is_shown_only_to_roles_that_see_credit(): void
    {
        Customer::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Juan Dela Cruz', 'phone' => '09171234567',
            'loyalty_points' => 120, 'credit_enabled' => true, 'credit_limit' => 5000, 'credit_balance' => 1200,
        ]);
        $otherBusiness = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(8))]);
        Customer::query()->create(['domain' => $otherBusiness->name_slug, 'name' => 'Juan Santos', 'loyalty_points' => 9]);

        $front = $this->ask($this->user(['customers.index']), 'find_customer', ['query' => 'Juan']);
        $this->assertSame(['Juan Dela Cruz'], array_column($front['customers'], 'name'));
        $this->assertSame(120, $front['customers'][0]['loyalty_points']);
        $this->assertArrayNotHasKey('credit', $front['customers'][0]);

        $credit = $this->ask($this->user(['customers.index', 'credits.index']), 'find_customer', ['query' => 'Juan']);
        $this->assertEquals(1200, $credit['customers'][0]['credit']['balance_owed']);
        $this->assertEquals(3800, $credit['customers'][0]['credit']['available']);
    }

    public function test_voids_cash_and_movements_answer_for_this_business_only(): void
    {
        $manager = $this->user(['voids.index', 'wallet.money-movement', 'inventory.movements']);
        $burger = $this->product('Burger');
        $sale = $this->sell($manager, $burger, 2);
        $item = SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $burger->id, 'quantity' => 1, 'unit_price' => 50]);
        VoidLog::query()->create(['sale_item_id' => $item->id, 'user_id' => $manager->id, 'approver_id' => $manager->id, 'reason' => 'Wrong order', 'amount' => 50]);
        $item->delete();

        $voids = $this->ask($manager, 'voids');
        $this->assertSame(1, $voids['count']);
        $this->assertSame('Burger', $voids['voids'][0]['product']);
        $this->assertSame('Wrong order', $voids['voids'][0]['reason']);

        $cash = $this->ask($manager, 'expected_cash_today');
        $this->assertSame(0, $cash['stores_with_a_shift_today']);
        $this->assertStringContainsString('No cash shift', $cash['note']);

        $this->assertSame('Burger', $this->ask($manager, 'stock_movements', ['product_id' => $burger->id])['product']);
        $otherBusinessProduct = Product::factory()->create(['domain' => 'someone-else', 'category_id' => $this->category->id]);
        $this->assertArrayHasKey('error', $this->ask($manager, 'stock_movements', ['product_id' => $otherBusinessProduct->id]));
    }

    public function test_bad_dates_are_explained_not_guessed(): void
    {
        $manager = $this->user(['sales-history.index']);

        $this->assertSame('Dates must be YYYY-MM-DD.', $this->ask($manager, 'sales_summary', ['start_date' => 'last tuesday-ish'])['error']);
        $this->assertSame('Ask for at most one year at a time.', $this->ask($manager, 'sales_summary', ['start_date' => '2024-01-01', 'end_date' => '2026-01-01'])['error']);
    }
}
