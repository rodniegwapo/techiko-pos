<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Order from a supplier, receive in deliveries: stock comes in and the supplier bill follows. */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $warehouse;

    private Product $rice;

    private Product $oil;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
        $this->seed(\Database\Seeders\ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create(['name' => 'Buy Org', 'name_slug' => 'buy-org-'.Str::lower(Str::random(6))]);
        $this->warehouse = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Warehouse', 'code' => Str::upper(Str::random(8)),
            'type' => 'warehouse', 'is_active' => true, 'is_default' => true,
        ]);
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true]);
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $make = fn (string $name) => Product::factory()->create([
            'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'name' => $name,
            'track_inventory' => true, 'sold_type' => 'Piece', 'cost' => 10,
        ]);
        $this->rice = $make('Rice');
        $this->oil = $make('Oil');
    }

    private function route(string $name, array $params = []): string
    {
        return route($name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    private function onHand(Product $product): float
    {
        return (float) ProductInventory::query()->where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)->value('quantity_on_hand');
    }

    private function sendOrder(): PurchaseOrder
    {
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.store'), [
            'supplier_name' => 'Golden Grains',
            'location_id' => $this->warehouse->id,
            'send' => true,
            'lines' => [
                ['product_id' => $this->rice->id, 'quantity_ordered' => 10, 'unit_cost' => 40],
                ['product_id' => $this->oil->id, 'quantity_ordered' => 4, 'unit_cost' => 90],
            ],
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::query()->forDomain($this->domain->name_slug)->with('lines')->sole();
    }

    public function test_an_order_is_received_in_deliveries_and_billed_for_what_arrived(): void
    {
        $order = $this->sendOrder();
        $this->assertSame('ordered', $order->status);
        $this->assertSame('PO-0001', $order->po_number);
        $this->assertTrue(Supplier::query()->where('name', 'Golden Grains')->exists());

        $rice = $order->lines->firstWhere('product_id', $this->rice->id);
        $oil = $order->lines->firstWhere('product_id', $this->oil->id);

        // First delivery: part of the rice.
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.receive', ['purchaseOrder' => $order->id]), [
            'lines' => [['id' => $rice->id, 'quantity' => 6], ['id' => $oil->id, 'quantity' => 0]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('partial', $order->fresh()->status);
        $this->assertSame(6.0, $this->onHand($this->rice));
        $bill = SupplierBill::query()->where('purchase_order_id', $order->id)->sole();
        $this->assertEquals(240, (float) $bill->amount);
        $this->assertSame('inventory', $bill->bill_type);
        $this->assertTrue(InventoryMovement::query()->where('reference_type', 'PurchaseOrder')->where('reference_id', $order->id)->exists());

        // More than is still to come is refused.
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.receive', ['purchaseOrder' => $order->id]), [
            'lines' => [['id' => $rice->id, 'quantity' => 5]],
        ])->assertSessionHasErrors('lines');

        // The rest.
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.receive', ['purchaseOrder' => $order->id]), [
            'lines' => [['id' => $rice->id, 'quantity' => 4], ['id' => $oil->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(10.0, $this->onHand($this->rice));
        $this->assertSame(4.0, $this->onHand($this->oil));
        $this->assertEquals(760, (float) $bill->fresh()->amount);
        $this->assertSame(1, SupplierBill::query()->where('purchase_order_id', $order->id)->count());
    }

    public function test_a_draft_is_edited_sent_and_can_be_cancelled(): void
    {
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.store'), [
            'supplier_name' => 'Oil Co', 'location_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity_ordered' => 2, 'unit_cost' => 90]],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::query()->forDomain($this->domain->name_slug)->sole();
        $this->assertSame('draft', $order->status);

        // A draft can't be received yet.
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.receive', ['purchaseOrder' => $order->id]), [
            'lines' => [['id' => $order->lines()->first()->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('status');

        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.order', ['purchaseOrder' => $order->id]))->assertSessionHasNoErrors();
        $this->assertSame('ordered', $order->fresh()->status);

        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.cancel', ['purchaseOrder' => $order->id]))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, SupplierBill::query()->count());
    }

    public function test_another_organizations_order_is_not_found(): void
    {
        $order = $this->sendOrder();
        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(6))]);

        $this->actingAs($this->admin)
            ->post(route('domains.purchase-orders.cancel', ['domain' => $other->name_slug, 'purchaseOrder' => $order->id]))
            ->assertNotFound();
        $this->assertSame('ordered', $order->fresh()->status);
    }
}
