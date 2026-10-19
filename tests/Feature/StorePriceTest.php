<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A product can sell at its own price at one store; elsewhere it keeps the product's price. */
class StorePriceTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $makati;

    private InventoryLocation $cebu;

    private Product $product;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
        $this->seed(\Database\Seeders\ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create(['name' => 'Price Org', 'name_slug' => 'price-org-'.Str::lower(Str::random(6))]);
        $store = fn (string $name, bool $default) => InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $default,
        ]);
        $this->makati = $store('Makati', true);
        $this->cebu = $store('Cebu', false);
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->makati->id]);

        $this->product = Product::factory()->create([
            'domain' => $this->domain->name_slug,
            'category_id' => Category::factory()->create(['domain' => $this->domain->name_slug])->id,
            'price' => 100, 'track_inventory' => false, 'sold_type' => 'Piece',
        ]);
        foreach ([$this->makati, $this->cebu] as $location) {
            $this->product->addToLocation($location);
            ProductInventory::query()->create([
                'product_id' => $this->product->id, 'location_id' => $location->id,
                'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'quantity_available' => 0,
            ]);
        }
        $this->product->locations()->updateExistingPivot($this->makati->id, ['price' => 120]);
    }

    private function catalogPrice(InventoryLocation $at): float
    {
        return (float) $this->actingAs($this->admin)
            ->getJson(route('domains.sales.products', ['domain' => $this->domain->name_slug, 'location_id' => $at->id]))
            ->assertOk()
            ->json('data.0.price');
    }

    public function test_the_pos_catalog_shows_each_stores_price(): void
    {
        $this->assertSame(120.0, $this->catalogPrice($this->makati));
        $this->assertSame(100.0, $this->catalogPrice($this->cebu));
    }

    public function test_a_line_is_priced_at_its_stores_price(): void
    {
        $sale = Sale::query()->create([
            'user_id' => $this->admin->id, 'location_id' => $this->makati->id, 'domain' => $this->domain->name_slug,
            'payment_status' => 'pending', 'invoice_number' => Str::upper(Str::random(10)), 'transaction_date' => now(),
            'grand_total' => 0, 'total_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'payment_method' => 'cash',
        ]);

        $this->actingAs($this->admin)->postJson(route('domains.sales.cart.add', [
            'domain' => $this->domain->name_slug, 'sale' => $sale->id,
        ]), ['product_id' => $this->product->id, 'quantity' => 2])->assertSuccessful();

        $this->assertEquals(120, (float) $sale->saleItems()->first()->unit_price);
    }

    public function test_an_offline_sale_uses_the_stores_price_not_the_devices(): void
    {
        $this->actingAs($this->admin)->postJson(route('domains.sales.offline-sync', ['domain' => $this->domain->name_slug]), [
            'sales' => [[
                'client_mutation_id' => (string) Str::uuid(),
                'payload' => [
                    'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 50]],
                    'payment_method' => 'cash',
                    'location_id' => $this->makati->id,
                    'cashier_user_id' => $this->admin->id,
                ],
            ]],
        ])->assertOk();

        $line = Sale::query()->where('location_id', $this->makati->id)->latest('id')->first()?->saleItems()->first();
        $this->assertNotNull($line);
        $this->assertEquals(120, (float) $line->unit_price);
    }

    public function test_the_price_is_set_and_cleared_from_the_product_page(): void
    {
        $url = route('domains.products.update', ['domain' => $this->domain->name_slug, 'product' => $this->product->id, 'location_id' => $this->cebu->id]);
        $base = [
            'name' => $this->product->name, 'price' => 100, 'category_id' => $this->product->category_id,
            'sold_type' => $this->product->sold_type, 'SKU' => $this->product->SKU, 'barcode' => $this->product->barcode,
            'representation_type' => 'color', 'track_inventory' => false,
        ];

        $this->actingAs($this->admin)->put($url, $base + ['store_price' => 95.5])->assertSessionHasNoErrors();
        $this->assertSame(95.5, $this->product->storePriceAt($this->cebu->id));

        $this->actingAs($this->admin)->put($url, $base + ['store_price' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->product->storePriceAt($this->cebu->id));
        $this->assertSame(120.0, $this->product->storePriceAt($this->makati->id));
    }
}
