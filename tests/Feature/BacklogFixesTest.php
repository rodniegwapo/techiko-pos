<?php

namespace Tests\Feature;

use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Models\LoyaltyTier;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\WalletCashReconciliation;
use App\Services\InventoryService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($cashier)->post($this->url('wallet-cash-ledger.end-shift'), [
            'location_id' => $this->store->id, 'business_date' => $today, 'end_shift_action' => 'save_as_opening_cash',
        ])->assertSessionHasNoErrors();
        $this->assertTrue((bool) WalletCashReconciliation::query()->sole()->is_closed);
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
