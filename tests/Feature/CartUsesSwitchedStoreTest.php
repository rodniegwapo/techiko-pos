<?php

namespace Tests\Feature;

use App\Helpers;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Someone not tied to one store sells in the store they picked in the header, whatever their role
 * is called. Their cart, and its stock check, used to fall back to the default store unless the
 * role was literally "admin", so products with stock in the picked store said "Insufficient stock".
 */
class CartUsesSwitchedStoreTest extends TestCase
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

    private function store(Domain $domain, string $name, bool $default): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => $name,
            'code' => 'CS-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => $default,
        ]);
    }

    private function stockAt(Product $product, InventoryLocation $store, int $quantity): void
    {
        $product->activeLocations()->attach($store->id, ['is_active' => true]);
        ProductInventory::query()->create([
            'product_id' => $product->id,
            'location_id' => $store->id,
            'quantity_on_hand' => $quantity,
            'quantity_reserved' => 0,
            'quantity_available' => $quantity,
        ]);
    }

    public function test_a_custom_role_not_tied_to_a_store_sells_from_the_store_it_picked(): void
    {
        $domain = Domain::query()->create([
            'name' => 'Switch Org',
            'name_slug' => 'switch-org-'.Str::lower(Str::random(8)),
        ]);
        $main = $this->store($domain, 'Main', true);
        $branch = $this->store($domain, 'Branch', false);

        $product = Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => Category::factory()->create(['domain' => $domain->name_slug])->id,
            'sold_type' => 'Piece',
            'track_inventory' => true,
            'price' => 100,
        ]);
        $this->stockAt($product, $main, 0);
        $this->stockAt($product, $branch, 10);

        // Admin level, but the role has the organization's own name, and no assigned store.
        $owner = User::factory()->create([
            'domain' => $domain->name_slug,
            'role_level' => 2,
            'location_id' => null,
        ]);
        $owner->assignRole(Role::query()->firstOrCreate(['name' => 'owner', 'guard_name' => 'web']));

        $this->actingAs($owner)
            ->withSession([Helpers::selectedLocationSessionKey($domain->name_slug) => $branch->id])
            ->postJson(route('domains.users.sales.cart.add', [
                'domain' => $domain->name_slug,
                'user' => $owner->id,
            ]), ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk();

        $sale = Sale::query()->where('user_id', $owner->id)->where('payment_status', 'pending')->sole();
        $this->assertSame($branch->id, (int) $sale->location_id);
        $this->assertEquals(2, (int) $sale->saleItems()->value('quantity'));
    }
}
