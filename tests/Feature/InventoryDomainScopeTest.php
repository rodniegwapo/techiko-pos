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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Inertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryDomainScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
    }

    /**
     * @return array{domain: Domain, main: InventoryLocation, branch: InventoryLocation, user: User, category: Category}
     */
    private function seedTenant(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Test Org',
            'name_slug' => 'test-org-'.Str::lower(Str::random(6)),
        ]);

        $main = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => 'M'.Str::upper(Str::random(4)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $branch = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Branch',
            'code' => 'B'.Str::upper(Str::random(4)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'location_id' => $main->id,
        ]);

        $category = Category::factory()->create([
            'domain' => $domain->name_slug,
        ]);

        return compact('domain', 'main', 'branch', 'user', 'category');
    }

    public function test_domain_inventory_products_json_respects_location_id(): void
    {
        $ctx = $this->seedTenant();
        $product = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'track_inventory' => true,
            'price' => 10,
        ]);
        $product->activeLocations()->attach($ctx['main']->id, ['is_active' => true]);
        $product->activeLocations()->attach($ctx['branch']->id, ['is_active' => true]);

        ProductInventory::query()->create([
            'product_id' => $product->id,
            'location_id' => $ctx['main']->id,
            'quantity_on_hand' => 5,
            'quantity_reserved' => 0,
            'quantity_available' => 5,
        ]);
        ProductInventory::query()->create([
            'product_id' => $product->id,
            'location_id' => $ctx['branch']->id,
            'quantity_on_hand' => 42,
            'quantity_reserved' => 0,
            'quantity_available' => 42,
        ]);

        $url = route('domains.inventory.products', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])
            ->getJson($url.'?location_id='.$ctx['main']->id)
            ->assertOk()
            ->assertJsonPath('data.0.quantity_available', 5);

        $this->actingAs($ctx['user'])
            ->getJson($url.'?location_id='.$ctx['branch']->id)
            ->assertOk()
            ->assertJsonPath('data.0.quantity_available', 42);
    }

    public function test_api_receive_rejects_product_from_different_domain_than_location(): void
    {
        $ctxA = $this->seedTenant();
        $ctxB = $this->seedTenant();

        $productB = Product::factory()->create([
            'domain' => $ctxB['domain']->name_slug,
            'category_id' => $ctxB['category']->id,
            'track_inventory' => true,
            'price' => 10,
        ]);
        $productB->activeLocations()->attach($ctxB['main']->id, ['is_active' => true]);

        Sanctum::actingAs($ctxA['user']);

        $response = $this->postJson('/api/inventory/receive', [
            'location_id' => $ctxA['main']->id,
            'items' => [
                [
                    'product_id' => $productB->id,
                    'quantity' => 1,
                    'unit_cost' => 5,
                ],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_domain_products_index_only_lists_active_at_selected_store(): void
    {
        $ctx = $this->seedTenant();

        $onlyBranch = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'track_inventory' => true,
            'price' => 20,
        ]);
        $onlyBranch->activeLocations()->attach($ctx['branch']->id, ['is_active' => true]);

        $urlMain = route('domains.products.index', ['domain' => $ctx['domain']->name_slug]).'?location_id='.$ctx['main']->id;
        $this->actingAs($ctx['user'])
            ->get($urlMain)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Products/Index')
                ->has('items.data', 0));

        $urlBranch = route('domains.products.index', ['domain' => $ctx['domain']->name_slug]).'?location_id='.$ctx['branch']->id;
        $this->actingAs($ctx['user'])
            ->get($urlBranch)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Products/Index')
                ->has('items.data', 1)
                ->where('items.data.0.id', $onlyBranch->id));
    }
}
