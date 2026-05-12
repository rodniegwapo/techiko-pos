<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\User;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesProductsCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
        $this->seed(ProductSoldTypeSeeder::class);
    }

    /**
     * @return array{domain: Domain, locA: InventoryLocation, locB: InventoryLocation, user: User, category: Category}
     */
    private function seedTwoLocations(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Catalog Org',
            'name_slug' => 'cat-org-'.Str::lower(Str::random(8)),
        ]);

        $locA = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => 'A-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $locB = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store B',
            'code' => 'B-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $locA->id,
        ]);

        $category = Category::factory()->create([
            'domain' => $domain->name_slug,
        ]);

        return [
            'domain' => $domain,
            'locA' => $locA,
            'locB' => $locB,
            'user' => $user,
            'category' => $category,
        ];
    }

    public function test_products_paginates_beyond_thirty(): void
    {
        $ctx = $this->seedTwoLocations();

        for ($i = 1; $i <= 35; $i++) {
            $p = Product::factory()->create([
                'domain' => $ctx['domain']->name_slug,
                'category_id' => $ctx['category']->id,
                'sold_type' => 'Piece',
                'barcode' => 'PAG-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
            $p->activeLocations()->attach($ctx['locA']->id, ['is_active' => true]);
        }

        $url = route('domains.sales.products', ['domain' => $ctx['domain']->name_slug]);
        $q = http_build_query([
            'location_id' => $ctx['locA']->id,
            'page' => 1,
            'per_page' => 30,
        ]);

        $response = $this->actingAs($ctx['user'])->getJson($url.'?'.$q);
        $response->assertOk();
        $response->assertJsonCount(30, 'data');
        $response->assertJsonPath('meta.total', 35);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.current_page', 1);

        $q2 = http_build_query([
            'location_id' => $ctx['locA']->id,
            'page' => 2,
            'per_page' => 30,
        ]);
        $page2 = $this->actingAs($ctx['user'])->getJson($url.'?'.$q2);
        $page2->assertOk();
        $page2->assertJsonCount(5, 'data');
        $page2->assertJsonPath('meta.current_page', 2);
    }

    public function test_products_respect_location_filter(): void
    {
        $ctx = $this->seedTwoLocations();

        $onlyA = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ONLY-A-'.Str::random(8),
        ]);
        $onlyA->activeLocations()->attach($ctx['locA']->id, ['is_active' => true]);

        $onlyB = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ONLY-B-'.Str::random(8),
        ]);
        $onlyB->activeLocations()->attach($ctx['locB']->id, ['is_active' => true]);

        $url = route('domains.sales.products', ['domain' => $ctx['domain']->name_slug]);

        $rA = $this->actingAs($ctx['user'])->getJson($url.'?'.http_build_query([
            'location_id' => $ctx['locA']->id,
            'per_page' => 50,
        ]));
        $rA->assertOk();
        $idsA = collect($rA->json('data'))->pluck('id');
        $this->assertTrue($idsA->contains($onlyA->id));
        $this->assertFalse($idsA->contains($onlyB->id));

        $rB = $this->actingAs($ctx['user'])->getJson($url.'?'.http_build_query([
            'location_id' => $ctx['locB']->id,
            'per_page' => 50,
        ]));
        $rB->assertOk();
        $idsB = collect($rB->json('data'))->pluck('id');
        $this->assertTrue($idsB->contains($onlyB->id));
        $this->assertFalse($idsB->contains($onlyA->id));
    }

    public function test_null_sku_product_appears_in_sales_catalog(): void
    {
        $ctx = $this->seedTwoLocations();

        $noSku = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'SKU' => null,
            'barcode' => 'NULL-SKU-'.Str::random(10),
        ]);
        $noSku->activeLocations()->attach($ctx['locA']->id, ['is_active' => true]);

        $url = route('domains.sales.products', ['domain' => $ctx['domain']->name_slug]);
        $response = $this->actingAs($ctx['user'])->getJson($url.'?'.http_build_query([
            'location_id' => $ctx['locA']->id,
            'per_page' => 100,
        ]));

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $noSku->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('SKU', $row);
        $this->assertNull($row['SKU']);
    }
}
