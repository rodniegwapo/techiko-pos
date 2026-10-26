<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\OpensTodaysShift;
use Tests\TestCase;

/** Selling by weight and volume: 1.5 kg of rice, 0.75 L of oil; whole numbers for everything else. */
class WeighedProductsTest extends TestCase
{
    use OpensTodaysShift;
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $branch;

    private User $user;

    private Product $rice;

    private Product $soda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openTodaysShiftForNewStores();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
        $this->seed(ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create(['name' => 'Sari-sari', 'name_slug' => 'sari-'.Str::lower(Str::random(6))]);
        $make = fn (string $name, bool $default) => InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $default,
        ]);
        $this->store = $make('Main', true);
        $this->branch = $make('Branch', false);
        $this->user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $product = function (string $name, string $soldType, float $price) use ($category) {
            $p = Product::factory()->create([
                'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'name' => $name,
                'sold_type' => $soldType, 'track_inventory' => true, 'price' => $price, 'cost' => $price / 2,
            ]);
            $p->activeLocations()->attach($this->store->id, ['is_active' => true]);
            $p->activeLocations()->attach($this->branch->id, ['is_active' => true]);
            ProductInventory::query()->create([
                'product_id' => $p->id, 'location_id' => $this->store->id,
                'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'quantity_available' => 10,
            ]);

            return $p;
        };
        $this->rice = $product('Rice', 'Kilo', 50);
        $this->soda = $product('Soda', 'Piece', 20);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    private function onHand(Product $product, ?InventoryLocation $at = null): float
    {
        return (float) ProductInventory::query()->where('product_id', $product->id)
            ->where('location_id', ($at ?? $this->store)->id)->value('quantity_on_hand');
    }

    private function addToCart(Product $product, float $quantity)
    {
        return $this->actingAs($this->user)->postJson(
            $this->url('users.sales.cart.add', ['user' => $this->user->id]),
            ['product_id' => $product->id, 'quantity' => $quantity, 'location_id' => $this->store->id],
        );
    }

    public function test_a_product_knows_how_it_is_counted(): void
    {
        $this->assertTrue($this->rice->allowsDecimal());
        $this->assertSame('kg', $this->rice->quantityUnit());
        $this->assertFalse($this->soda->allowsDecimal());
        $this->assertSame('pc', $this->soda->quantityUnit());
        $this->assertSame('kg', $this->rice->toArray()['quantity_unit']);
    }

    public function test_one_and_a_half_kilos_are_sold_and_taken_from_stock(): void
    {
        $this->addToCart($this->rice, 1.5)->assertOk();

        $sale = Sale::query()->where('payment_status', 'pending')->sole();
        $line = $sale->saleItems()->sole();
        $this->assertSame(1.5, $line->quantity);
        $this->assertEquals(75, (float) $sale->fresh()->grand_total);

        $this->actingAs($this->user)
            ->postJson($this->url('sales.payment.store', ['sale' => $sale->id]), ['payment_method' => 'cash'])
            ->assertOk();

        $this->assertSame(8.5, $this->onHand($this->rice));
    }

    public function test_a_product_sold_by_the_piece_takes_whole_numbers_only(): void
    {
        $this->addToCart($this->soda, 1.5)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->addToCart($this->soda, 2)->assertOk();
    }

    public function test_a_purchase_order_delivery_of_one_and_a_half_kilos_keeps_the_half(): void
    {
        $this->actingAs($this->user)->post($this->url('purchase-orders.store'), [
            'supplier_name' => 'Rice Mill', 'location_id' => $this->store->id, 'send' => true,
            'lines' => [['product_id' => $this->rice->id, 'quantity_ordered' => 2, 'unit_cost' => 40]],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::query()->with('lines')->sole();

        $this->actingAs($this->user)->post($this->url('purchase-orders.receive', ['purchaseOrder' => $order->id]), [
            'lines' => [['id' => $order->lines->first()->id, 'quantity' => 1.5]],
        ])->assertSessionHasNoErrors();

        // It used to be cut to a whole number in stock while the order kept 1.5.
        $this->assertSame(11.5, $this->onHand($this->rice));
    }

    public function test_receiving_and_transferring_parts_of_a_kilo(): void
    {
        $this->actingAs($this->user)->postJson($this->url('inventory.receive'), [
            'location_id' => $this->store->id,
            'items' => [['product_id' => $this->rice->id, 'quantity' => 2.25, 'unit_cost' => 25]],
        ])->assertSuccessful();
        $this->assertSame(12.25, $this->onHand($this->rice));

        $this->actingAs($this->user)->postJson($this->url('inventory.receive'), [
            'location_id' => $this->store->id,
            'items' => [['product_id' => $this->soda->id, 'quantity' => 0.5, 'unit_cost' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        $this->actingAs($this->user)->postJson($this->url('inventory.transfer'), [
            'from_location_id' => $this->store->id, 'to_location_id' => $this->branch->id,
            'product_id' => $this->rice->id, 'quantity' => 0.5,
        ])->assertSuccessful();
        $this->assertSame(11.75, $this->onHand($this->rice));
        $this->assertSame(0.5, $this->onHand($this->rice, $this->branch));
    }
}
