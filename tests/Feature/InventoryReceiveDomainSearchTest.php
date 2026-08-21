<?php

namespace Tests\Feature;

use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\User;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryReceiveDomainSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
        ]);
        $this->seed(ProductSoldTypeSeeder::class);
    }

    /**
     * @return array{domain: Domain, locA: InventoryLocation, locB: InventoryLocation, user: User, category: Category, product: Product}
     */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Receive Org',
            'name_slug' => 'receive-org-'.Str::lower(Str::random(8)),
        ]);

        $locA = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => 'RA-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $locB = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store B',
            'code' => 'RB-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $locB->id,
        ]);

        $category = Category::factory()->create([
            'domain' => $domain->name_slug,
        ]);

        // Product only at Store A — not at Store B
        $product = Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'sold_type' => 'Piece',
            'barcode' => 'RECV-LI-'.Str::upper(Str::random(4)),
            'SKU' => 'SKU-RECV-'.Str::upper(Str::random(4)),
            'name' => 'Liempo Test Cut',
            'cost' => 100,
            'price' => 150,
            'track_inventory' => true,
        ]);
        $product->activeLocations()->sync([$locA->id => ['is_active' => true]]);

        return [
            'domain' => $domain,
            'locA' => $locA,
            'locB' => $locB,
            'user' => $user,
            'category' => $category,
            'product' => $product,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productsFromSearchResponse($response): array
    {
        $body = $response->json('data');
        if (is_array($body) && array_is_list($body)) {
            return $body;
        }
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return [];
    }

    public function test_search_scope_domain_includes_product_not_at_location(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])->getJson('/api/inventory/search/products?'.http_build_query([
            'search' => 'Liempo',
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['locB']->id,
            'scope' => 'domain',
        ]));

        $response->assertOk();
        $products = $this->productsFromSearchResponse($response);
        $match = collect($products)->firstWhere('id', $ctx['product']->id);

        $this->assertNotNull($match, 'Expected product to appear in domain-scoped search');
        $this->assertFalse((bool) ($match['at_location'] ?? true));
    }

    public function test_search_without_scope_excludes_product_not_at_location(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])->getJson('/api/inventory/search/products?'.http_build_query([
            'search' => 'Liempo',
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['locB']->id,
        ]));

        $response->assertOk();
        $products = $this->productsFromSearchResponse($response);
        $ids = collect($products)->pluck('id')->all();

        $this->assertNotContains($ctx['product']->id, $ids);
    }

    public function test_receive_auto_attaches_product_to_store(): void
    {
        $ctx = $this->seedContext();

        $this->assertFalse($ctx['product']->isAvailableAt($ctx['locB']));

        $response = $this->actingAs($ctx['user'])->postJson('/api/inventory/receive', [
            'location_id' => $ctx['locB']->id,
            'items' => [
                [
                    'product_id' => $ctx['product']->id,
                    'quantity' => 5,
                    'unit_cost' => 100,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $ctx['product']->refresh();
        $this->assertTrue($ctx['product']->isAvailableAt($ctx['locB']));

        $inventory = ProductInventory::query()
            ->where('product_id', $ctx['product']->id)
            ->where('location_id', $ctx['locB']->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertSame(5, (int) $inventory->quantity_on_hand);
    }
}
