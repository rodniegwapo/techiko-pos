<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesOfflineCatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            RoleBasedAccessControl::class,
        ]);
    }

    /**
     * @return array{domain: Domain, location: InventoryLocation, user: User, product: Product}
     */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Test Org',
            'name_slug' => 'test-org-'.Str::lower(Str::random(6)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'location_id' => $location->id,
        ]);

        $category = Category::factory()->create([
            'domain' => $domain->name_slug,
        ]);

        $product = Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'track_inventory' => true,
            'price' => 100,
        ]);

        $product->activeLocations()->attach($location->id, ['is_active' => true]);

        ProductInventory::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity_on_hand' => 100,
            'quantity_reserved' => 0,
            'quantity_available' => 100,
        ]);

        return compact('domain', 'location', 'user', 'product');
    }

    public function test_non_super_user_without_sales_offline_catalog_permission_gets_403(): void
    {
        $ctx = $this->seedContext();

        $url = route('domains.sales.offline-catalog', ['domain' => $ctx['domain']->name_slug]);

        $response = $this->actingAs($ctx['user'])->getJson($url.'?location_id='.$ctx['location']->id);

        $response->assertForbidden();
    }

    public function test_non_super_user_with_sales_offline_catalog_permission_gets_catalog_json(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $ctx = $this->seedContext();

        $permission = Permission::query()->create([
            'name' => 'Test sales.offline-catalog '.Str::uuid(),
            'guard_name' => 'web',
            'route_name' => 'sales.offline-catalog',
        ]);

        $ctx['user']->givePermissionTo($permission);

        $url = route('domains.sales.offline-catalog', ['domain' => $ctx['domain']->name_slug]);

        $response = $this->actingAs($ctx['user'])->getJson($url.'?location_id='.$ctx['location']->id);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonCount(1, 'data');
    }
}
