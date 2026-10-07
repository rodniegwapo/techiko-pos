<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A store's Products page only lists products assigned to it, so stock that arrives at a store by
 * transfer or by an approved adjustment must assign the product there too. Also covers the
 * duplicate-SKU message, which used to read "The s k u has already been taken."
 */
class StockReachesDestinationStoreTest extends TestCase
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
        $this->seed(ProductSoldTypeSeeder::class);
    }

    /**
     * @return array{domain: Domain, locA: InventoryLocation, locB: InventoryLocation, user: User, product: Product}
     */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Transfer Org',
            'name_slug' => 'transfer-org-'.Str::lower(Str::random(8)),
        ]);

        $locA = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => 'TA-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $locB = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store B',
            'code' => 'TB-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $locA->id,
        ]);

        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        // Stocked at Store A only; Store B has never carried it.
        $product = Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'sold_type' => 'Piece',
            'barcode' => 'XFER-'.Str::upper(Str::random(6)),
            'SKU' => 'SKU-XFER-'.Str::upper(Str::random(6)),
            'name' => 'Transfer Test Widget',
            'cost' => 100,
            'price' => 150,
            'track_inventory' => true,
        ]);
        $product->activeLocations()->sync([$locA->id => ['is_active' => true]]);

        app(InventoryService::class)->receiveInventory(
            [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100]],
            $user,
            $locA,
        );

        return compact('domain', 'locA', 'locB', 'user', 'product');
    }

    private function quantityAt(Product $product, InventoryLocation $location): int
    {
        return (int) ProductInventory::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->value('quantity_on_hand');
    }

    public function test_transfer_assigns_the_product_to_the_destination_store(): void
    {
        $ctx = $this->seedContext();
        $this->assertFalse($ctx['product']->isAvailableAt($ctx['locB']));

        app(InventoryService::class)->transferInventory($ctx['product'], $ctx['locA'], $ctx['locB'], 4, $ctx['user']);

        $ctx['product']->refresh();
        $this->assertTrue($ctx['product']->isAvailableAt($ctx['locB']));
        $this->assertSame(4, $this->quantityAt($ctx['product'], $ctx['locB']));
        $this->assertSame(6, $this->quantityAt($ctx['product'], $ctx['locA']));
    }

    public function test_approved_adjustment_assigns_the_product_to_its_store(): void
    {
        $ctx = $this->seedContext();
        $this->assertFalse($ctx['product']->isAvailableAt($ctx['locB']));

        $adjustment = app(InventoryService::class)->createStockAdjustment([
            'location_id' => $ctx['locB']->id,
            'domain' => $ctx['domain']->name_slug,
            'type' => 'recount',
            'reason' => 'physical_count',
        ], [
            ['product_id' => $ctx['product']->id, 'actual_quantity' => 3],
        ], $ctx['user']);

        $adjustment->approve($ctx['user']);

        $ctx['product']->refresh();
        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertTrue($ctx['product']->isAvailableAt($ctx['locB']));
        $this->assertSame(3, $this->quantityAt($ctx['product'], $ctx['locB']));
    }

    public function test_duplicate_sku_message_names_the_field_properly(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])->postJson(
            route('domains.products.store', ['domain' => $ctx['domain']->name_slug]),
            [
                'name' => 'Another Widget',
                'sold_type' => 'Piece',
                'price' => 10,
                'barcode' => 'XFER-OTHER-'.Str::upper(Str::random(6)),
                'SKU' => $ctx['product']->SKU,
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.SKU.0', 'The SKU has already been taken.');
    }
}
