<?php

namespace Tests\Feature;

use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Models\LoyaltyTier;
use App\Models\Permission;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\RegisterShift;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Services\InventoryService;
use App\Support\Wallet\WalletCashDailyExpected;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Fixes from the bugs/suggestions board (batch 1). */
class BacklogFixesTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, ValidateCsrfToken::class]);

        $this->domain = Domain::query()->create([
            'name' => 'Backlog Org',
            'name_slug' => 'backlog-org-'.Str::lower(Str::random(6)),
            'settings' => ['features' => ['finance' => true]],
        ]);
        $make = fn (string $name, string $type, bool $default) => InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => $type, 'is_active' => true, 'is_default' => $default,
        ]);
        $this->store = $make('Lianga Branch', 'store', true);
        $this->warehouse = $make('Main Warehouse', 'warehouse', false);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    public function test_an_owner_without_a_store_pays_a_bill_from_the_drawer_they_pick(): void
    {
        // role_level defaults to 3 (manager) for staff made in the app; with no store of their own,
        // the store they pick is kept.
        $owner = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'role_level' => 3, 'location_id' => null]);
        $supplier = Supplier::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Richard', 'payment_terms_days' => 30, 'is_active' => true]);
        $bill = SupplierBill::query()->create([
            'domain' => $this->domain->name_slug, 'supplier_id' => $supplier->id, 'bill_type' => 'inventory', 'user_id' => $owner->id,
            'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(), 'amount' => 500,
        ]);
        $pay = fn (array $more) => $this->actingAs($owner)->post($this->url('finance.bill-payments.store', ['bill' => $bill->id]), [
            'payment_date' => today()->toDateString(), 'amount' => 200, 'payment_method' => 'cash_register', ...$more,
        ]);

        $pay([])->assertSessionHasErrors(['location_id' => 'Pick which store\'s cash drawer paid this.']);

        $pay(['location_id' => $this->store->id])->assertSessionHasNoErrors();
        $this->assertSame($this->store->id, (int) SupplierPayment::query()->sole()->location_id);
    }

    public function test_inventory_lists_the_stores_products_with_their_category(): void
    {
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $category = Category::factory()->create(['domain' => $this->domain->name_slug, 'name' => 'V2 Pods']);
        $make = fn (string $name) => Product::factory()->create([
            'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'name' => $name, 'track_inventory' => true,
        ]);
        $carried = $make('Mamba Max V2');
        $removed = $make('Black Pod Formula');
        $carried->addToLocation($this->store);
        foreach ([$carried, $removed] as $product) {
            ProductInventory::query()->create([
                'product_id' => $product->id, 'location_id' => $this->store->id,
                'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'quantity_available' => 0,
            ]);
        }

        // Black Pod Formula was taken off the store: its empty stock row isn't listed.
        $this->actingAs($user)->getJson($this->url('inventory.products', ['location_id' => $this->store->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.name', 'Mamba Max V2')
            ->assertJsonPath('data.0.product.category.name', 'V2 Pods');
    }

    public function test_a_cashier_counts_and_ends_the_shift_but_sees_only_what_should_be_there(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $role->forceFill(['level' => 4])->save();
        $cashier = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $cashier->assignRole($role);
        $today = today()->toDateString();
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'business_date' => $today, 'opening_cash' => 500,
        ]);

        $this->actingAs($cashier)->post($this->url('wallet-cash-ledger.counted-cash.store'), [
            'location_id' => $this->store->id, 'business_date' => $today, 'counted_cash' => 450,
        ])->assertSessionHasNoErrors();

        $this->actingAs($cashier)->get($this->url('wallet.drawer', ['location_id' => $this->store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Wallet/Drawer')
                ->where('canSeeCountResult', false)
                ->where('cashControl.expected_cash', 500)
                ->where('cashControl.variance', null)
                ->where('cashControl.count_submission_history', [])
                ->missing('cashControl.bridge_expected_cash'));

        // A cashier ends their own shift; ending the store's day is a manager's.
        $this->actingAs($cashier)->post($this->url('wallet-cash-ledger.end-shift'), [
            'location_id' => $this->store->id, 'business_date' => $today, 'end_shift_action' => 'save_as_opening_cash',
        ])->assertSessionHasErrors('end_shift_action');
        $this->assertFalse((bool) WalletCashReconciliation::query()->sole()->is_closed);
    }

    public function test_customers_follow_the_tiers_set_in_tier_management(): void
    {
        $admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $tier = fn (string $name, string $display, float $threshold, int $order) => LoyaltyTier::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'display_name' => $display, 'multiplier' => 1,
            'spending_threshold' => $threshold, 'color' => '#123456', 'sort_order' => $order, 'is_active' => true,
        ]);
        $tier('suki', 'Suki', 0, 1);
        $vip = $tier('vip', 'VIP', 1000, 2);

        // A new customer starts on the organization's own lowest tier, not "bronze".
        $customer = Customer::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Juan', 'lifetime_spent' => 0]);
        $this->assertSame('suki', $customer->tier);
        $this->assertSame('Suki', $customer->getTierInfo()['name']);

        $customer->update(['lifetime_spent' => 1500]);
        $customer->updateTierBasedOnSpending();
        $this->assertSame('VIP', $customer->fresh()->getTierInfo()['name']);

        // Switching VIP off in Tier Management moves its customers back.
        $this->actingAs($admin)->putJson($this->url('loyalty.tiers.update', ['tier' => $vip->id]), [
            'name' => 'vip', 'display_name' => 'VIP', 'multiplier' => 1, 'spending_threshold' => 1000,
            'color' => '#123456', 'sort_order' => 2, 'is_active' => false,
        ])->assertOk();
        $this->assertSame('suki', $customer->fresh()->tier);
    }

    public function test_a_cashier_never_gets_product_cost(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $role->forceFill(['level' => 4])->save();
        $cashier = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $cashier->assignRole($role);
        $admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $product = Product::factory()->create([
            'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'track_inventory' => true, 'cost' => 300, 'price' => 400,
        ]);
        $product->addToLocation($this->store);
        ProductInventory::query()->create([
            'product_id' => $product->id, 'location_id' => $this->store->id, 'quantity_on_hand' => 5,
            'quantity_reserved' => 0, 'quantity_available' => 5, 'average_cost' => 300, 'total_value' => 1500,
        ]);
        $url = $this->url('inventory.products', ['location_id' => $this->store->id]);

        $row = $this->actingAs($cashier)->getJson($url)->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('average_cost', $row);
        $this->assertArrayNotHasKey('total_value', $row);
        $this->assertArrayNotHasKey('cost', $row['product']);
        $this->assertEquals(400, $row['product']['price']);

        $row = $this->actingAs($admin)->getJson($url)->assertOk()->json('data.0');
        $this->assertEquals(300, $row['product']['cost']);
        $this->assertEquals(300, $row['average_cost']);
    }

    public function test_buying_at_a_new_price_averages_the_products_cost(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $product = Product::factory()->create([
            'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'track_inventory' => true, 'cost' => 100, 'sold_type' => 'Piece',
        ]);
        $product->addToLocation($this->store);
        ProductInventory::query()->create([
            'product_id' => $product->id, 'location_id' => $this->store->id, 'quantity_on_hand' => 10,
            'quantity_reserved' => 0, 'quantity_available' => 10, 'average_cost' => 100,
        ]);

        // 10 on hand at ₱100, 10 more bought at ₱160: each now cost ₱130 on average.
        app(InventoryService::class)->receiveInventory(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 160]],
            $admin,
            $this->store,
        );

        $this->assertEquals(130, (float) $product->fresh()->cost);
    }

    public function test_a_cashier_ends_their_own_shift_without_a_pin_and_a_manager_ends_the_day_after(): void
    {
        $this->domain->update(['settings' => ['features' => ['finance' => true, 'expenses' => true]]]);
        $cashierRole = Role::query()->firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $cashierRole->forceFill(['level' => 4])->save();
        $managerRole = Role::query()->firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $managerRole->forceFill(['level' => 3])->save();
        $cashier = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $cashier->assignRole($cashierRole);
        $manager = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $manager->assignRole($managerRole);
        $ice = ExpenseCategory::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Supplies', 'is_active' => true]);
        $today = today()->toDateString();

        // The first shift of the day opens the store's day with its cash.
        $this->actingAs($cashier)->post($this->url('register-shifts.start'), ['location_id' => $this->store->id, 'opening_cash' => 1000])
            ->assertSessionHasNoErrors();
        $this->assertEquals(1000, (float) WalletCashReconciliation::query()->sole()->opening_cash);
        $shift = RegisterShift::query()->sole();

        $closeDay = fn (User $user) => $this->actingAs($user)->post($this->url('wallet-cash-ledger.close-shift'), [
            'location_id' => $this->store->id, 'business_date' => $today, 'counted_cash' => 950, 'end_shift_action' => 'save_as_opening_cash',
        ]);
        // The store's day is a manager's to end, and only once nobody is still on shift.
        $closeDay($cashier)->assertSessionHasErrors('end_shift_action');
        $closeDay($manager)->assertSessionHasErrors('end_shift_action');
        $this->assertFalse((bool) WalletCashReconciliation::query()->sole()->is_closed);

        // No PIN: the ₱50 paid from the drawer comes out of Expected, so 950 against 1000 is exact.
        $this->actingAs($cashier)->post($this->url('register-shifts.end', ['registerShift' => $shift->id]), [
            'counted_cash' => 950,
            'expenses' => [['expense_category_id' => $ice->id, 'amount' => 50, 'description' => 'Ice']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('cash_register', Expense::query()->sole()->payment_method);
        $shift->refresh();
        $this->assertSame(RegisterShift::CLOSED, $shift->status);
        $this->assertEquals(950, $shift->expected_cash);
        $submission = WalletCashCountSubmission::query()->sole();
        $this->assertSame($shift->id, (int) $submission->register_shift_id);
        $this->assertEquals(0, (float) $submission->variance_snapshot);
        $this->assertNull($submission->approved_by);

        $closeDay($manager)->assertSessionHasNoErrors();
        $this->assertTrue((bool) WalletCashReconciliation::query()->sole()->is_closed);
    }

    public function test_a_manager_ends_the_shift_without_a_pin(): void
    {
        $admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $today = today()->toDateString();
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'business_date' => $today, 'opening_cash' => 300,
        ]);

        $this->actingAs($admin)->post($this->url('wallet-cash-ledger.close-shift'), [
            'location_id' => $this->store->id, 'business_date' => $today, 'counted_cash' => 300, 'end_shift_action' => 'cashout_now',
        ])->assertSessionHasNoErrors();

        $this->assertTrue((bool) WalletCashReconciliation::query()->sole()->is_closed);
        $this->assertNull(WalletCashCountSubmission::query()->sole()->approved_by);
    }

    public function test_owners_see_every_stores_money_side_by_side(): void
    {
        $admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $branch = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Cantilan Branch', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true,
        ]);
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $branch->id, 'business_date' => today()->toDateString(), 'opening_cash' => 700,
        ]);

        $this->actingAs($admin)->get($this->url('wallet.balances', ['location_id' => $this->store->id, 'tab' => 'stores']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'stores')
                ->has('allStores.stores', 2)
                ->where('allStores.stores.0.name', 'Cantilan Branch')
                ->where('allStores.stores.0.drawer', 700)
                ->where('allStores.total', 700));

        // At the warehouse (head office) the same overview sits on Stores' cash.
        $this->actingAs($admin)->get($this->url('wallet.drawer', ['location_id' => $this->warehouse->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Wallet/StoresCash')->has('allStores.stores', 2));

        // A store manager sees their own store only.
        $managerRole = Role::query()->firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $managerRole->forceFill(['level' => 3])->save();
        $manager = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $manager->assignRole($managerRole);
        $manager->givePermissionTo(Permission::query()->firstOrCreate(
            ['route_name' => 'wallet-cash-ledger.index', 'guard_name' => 'web'], ['name' => 'Test ledger '.Str::uuid()],
        ));
        $this->actingAs($manager)->get($this->url('wallet.balances', ['location_id' => $this->store->id, 'tab' => 'stores']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'balances')->where('allStores', null)->where('canSeeAllStores', false));
    }

    public function test_an_owner_withdrawal_can_go_into_another_stores_drawer(): void
    {
        $admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $branch = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Cantilan Branch', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true,
        ]);
        $today = today()->toDateString();
        $take = fn (array $more) => $this->actingAs($admin)->post($this->url('wallet-cash-ledger.store'), [
            'location_id' => $this->store->id, 'kind' => 'owner_draw', 'direction' => 'out', 'draw_source' => 'cash_register',
            'amount' => 2000, 'movement_date' => $today, ...$more,
        ]);

        $take(['to_location_id' => $branch->id])->assertSessionHasNoErrors();

        $out = WalletCashMovement::query()->where('location_id', $this->store->id)->sole();
        $in = WalletCashMovement::query()->where('location_id', $branch->id)->sole();
        $this->assertSame(['out', 'in'], [$out->direction, $in->direction]);
        $this->assertSame('owner_transfer_in', $in->kind);
        $this->assertNotNull($out->transfer_group);
        $this->assertSame($out->transfer_group, $in->transfer_group);

        // The branch's Expected goes up by what came in.
        $expected = WalletCashDailyExpected::compute($this->domain->name_slug, $branch->id, $today, null);
        $this->assertEquals(2000, $expected['manual_in']);

        // Not into a warehouse, nor into a store whose day is already closed.
        $take(['to_location_id' => $this->warehouse->id])->assertSessionHasErrors('to_location_id');
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $branch->id, 'business_date' => $today, 'opening_cash' => 0, 'is_closed' => true,
        ]);
        $take(['to_location_id' => $branch->id])->assertSessionHasErrors('to_location_id');
        $this->assertSame(2, WalletCashMovement::query()->count());
    }

    public function test_finance_at_a_warehouse_covers_every_store(): void
    {
        $admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);

        // The warehouse is the business's head office: its Finance pages total every store.
        $this->actingAs($admin)->get($this->url('finance.dashboard', ['location_id' => $this->warehouse->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.location_id', null));

        $this->actingAs($admin)->get($this->url('finance.dashboard', ['location_id' => $this->store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.location_id', $this->store->id));

        $this->assertNull(InventoryLocation::reportScope($this->warehouse->id, $this->domain->name_slug));
        $this->assertNull(InventoryLocation::reportScope($this->store->id, 'another-business'));
    }

    public function test_store_performance_leaves_out_the_warehouse(): void
    {
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);

        $this->actingAs($user)->get($this->url('dashboard', ['location_id' => $this->store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.store_performance.total_locations', 1)
                ->where('stats.store_performance.locations.0.name', 'Lianga Branch'));
    }
}
