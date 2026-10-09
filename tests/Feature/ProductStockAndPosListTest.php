<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\SharedProductSuggestion;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Inertia;
use Tests\TestCase;

/**
 * The tester's quick wins: A–Z POS list, hiding out-of-stock products at the POS, the product
 * form's stock fields, low-stock status and filters, retail valuation, and internal barcodes.
 */
class ProductStockAndPosListTest extends TestCase
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

    /** @return array{domain: Domain, store: InventoryLocation, user: User, category: Category} */
    private function seedStore(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Quick Wins Org',
            'name_slug' => 'qw-org-'.Str::lower(Str::random(8)),
        ]);

        $store = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main Store',
            'code' => 'QW-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $store->id,
        ]);

        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        return compact('domain', 'store', 'user', 'category');
    }

    /** A product at the store with $qty in stock (none received when 0). */
    private function productAt(array $ctx, string $name, int $qty, array $attrs = []): Product
    {
        $product = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'name' => $name,
            'barcode' => 'QW-'.Str::upper(Str::random(8)),
            'SKU' => 'SKU-QW-'.Str::upper(Str::random(8)),
            'price' => 100,
            'cost' => 60,
            'track_inventory' => true,
            'reorder_level' => 0,
            ...$attrs,
        ]);
        $product->activeLocations()->attach($ctx['store']->id, ['is_active' => true]);

        if ($qty > 0) {
            app(InventoryService::class)->receiveInventory(
                [['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => 60]],
                $ctx['user'],
                $ctx['store'],
            );
        }

        return $product;
    }

    private function posProducts(array $ctx, array $query = [])
    {
        $url = route('domains.sales.products', ['domain' => $ctx['domain']->name_slug]);

        return $this->actingAs($ctx['user'])->getJson($url.'?'.http_build_query([
            'location_id' => $ctx['store']->id,
            ...$query,
        ]));
    }

    private function hideOutOfStock(Domain $domain): void
    {
        $settings = $domain->settings ?? [];
        $settings['sales']['hide_out_of_stock'] = true;
        $domain->update(['settings' => $settings]);
    }

    public function test_pos_list_is_in_alphabetical_order(): void
    {
        $ctx = $this->seedStore();
        $this->productAt($ctx, 'Zesty Lemonade', 5);
        $this->productAt($ctx, 'Americano', 5);
        $this->productAt($ctx, 'Mocha', 5);

        $this->posProducts($ctx)
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Americano', 'Mocha', 'Zesty Lemonade']);
    }

    public function test_pos_list_can_hide_out_of_stock_products_but_a_scan_still_finds_them(): void
    {
        $ctx = $this->seedStore();
        $this->productAt($ctx, 'In Stock Item', 5);
        $this->productAt($ctx, 'Sold Out Item', 0);
        $this->productAt($ctx, 'Made To Order', 0, ['track_inventory' => false]);

        $this->posProducts($ctx)->assertOk()->assertJsonPath('meta.total', 3);

        $this->hideOutOfStock($ctx['domain']);

        $this->posProducts($ctx)
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.*.name', ['In Stock Item', 'Made To Order']);

        $this->posProducts($ctx, ['search' => 'Sold Out', 'include_out_of_stock' => 1])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sold Out Item');
    }

    public function test_hide_out_of_stock_setting_is_saved(): void
    {
        $ctx = $this->seedStore();

        $this->actingAs($ctx['user'])
            ->patch(route('domains.settings.update', ['domain' => $ctx['domain']->name_slug]), ['hide_out_of_stock' => true])
            ->assertRedirect();

        $this->assertTrue($ctx['domain']->fresh()->salesHidesOutOfStock());
    }

    public function test_product_form_saves_track_stock_and_low_stock_level(): void
    {
        $ctx = $this->seedStore();
        $product = $this->productAt($ctx, 'Iced Latte', 0);
        $url = route('domains.products.update', ['domain' => $ctx['domain']->name_slug, 'product' => $product->id]);
        $payload = ['name' => 'Iced Latte', 'sold_type' => 'Piece', 'price' => 120];

        $this->actingAs($ctx['user'])
            ->put($url.'?location_id='.$ctx['store']->id, [...$payload, 'track_inventory' => false, 'reorder_level' => 5])
            ->assertRedirect(route('domains.products.index', ['domain' => $ctx['domain']->name_slug, 'location_id' => $ctx['store']->id]));

        $product->refresh();
        $this->assertFalse($product->track_inventory);
        $this->assertEquals(5, $product->reorder_level);

        // A cleared "Low stock level" is stored as 0 (the column can't be null).
        $this->actingAs($ctx['user'])->put($url, [...$payload, 'reorder_level' => null])->assertRedirect();
        $this->assertEquals(0, $product->fresh()->reorder_level);
    }

    public function test_products_list_flags_and_filters_low_and_out_of_stock(): void
    {
        $ctx = $this->seedStore();
        $this->productAt($ctx, 'Plenty', 50, ['reorder_level' => 10]);
        $low = $this->productAt($ctx, 'Running Low', 3, ['reorder_level' => 10]);
        $this->productAt($ctx, 'Gone', 0, ['reorder_level' => 10]);
        // The store's own level wins over the product's: 8 left is low here, though the product says 5.
        $storeLevel = $this->productAt($ctx, 'Store Level', 8, ['reorder_level' => 5]);
        ProductInventory::query()
            ->where('product_id', $storeLevel->id)
            ->where('location_id', $ctx['store']->id)
            ->update(['location_reorder_level' => 10]);

        $url = route('domains.products.index', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])
            ->get($url.'?location_id='.$ctx['store']->id.'&sort=name')
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Products/Index')
                ->where('stockCounts', ['low' => 2, 'out' => 1])
                ->where('items.data.0.name', 'Gone')
                ->where('items.data.0.location_stock_status', 'out_of_stock')
                ->where('items.data.1.name', 'Plenty')
                ->where('items.data.1.location_stock_status', 'in_stock')
                ->where('items.data.2.name', 'Running Low')
                ->where('items.data.2.location_stock_status', 'low_stock'));

        $this->actingAs($ctx['user'])
            ->get($url.'?location_id='.$ctx['store']->id.'&stock_status=low&sort=name')
            ->assertInertia(fn (Inertia $page) => $page
                ->has('items.data', 2)
                ->where('items.data.0.id', $low->id)
                ->where('items.data.1.id', $storeLevel->id));
    }

    public function test_valuation_shows_value_at_sale_price(): void
    {
        $ctx = $this->seedStore();
        $this->productAt($ctx, 'Pods', 10, ['price' => 150]);

        $this->actingAs($ctx['user'])
            ->get(route('domains.inventory.valuation', ['domain' => $ctx['domain']->name_slug]).'?location_id='.$ctx['store']->id)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Inventory/Valuation')
                ->where('summary.total_retail_value', 1500)
                ->where('summary.potential_profit', 900)
                ->where('items.0.retail_value', 1500));
    }

    public function test_internal_barcodes_are_not_suggested_to_the_shared_catalog(): void
    {
        $ctx = $this->seedStore();
        $url = route('domains.products.store', ['domain' => $ctx['domain']->name_slug]);

        foreach (['2001234567893' => 0, '4800016644115' => 1] as $barcode => $suggestions) {
            $this->actingAs($ctx['user'])->post($url.'?location_id='.$ctx['store']->id, [
                'name' => 'Item '.$barcode,
                'sold_type' => 'Piece',
                'price' => 10,
                'barcode' => (string) $barcode,
            ])->assertSessionHasNoErrors();

            $this->assertSame(
                $suggestions,
                SharedProductSuggestion::query()->where('barcode', (string) $barcode)->count(),
                "suggestions for {$barcode}",
            );
        }
    }
}
