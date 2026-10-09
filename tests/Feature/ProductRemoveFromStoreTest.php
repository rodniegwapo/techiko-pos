<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting a product can take it off one store's list or delete it from the whole organization.
 * Taking it off a store is refused while that store still has stock.
 */
class ProductRemoveFromStoreTest extends TestCase
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
            'name' => 'Remove Org',
            'name_slug' => 'remove-org-'.Str::lower(Str::random(8)),
        ]);

        $makeStore = fn (string $name, bool $default) => InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => $name,
            'code' => 'RM-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => $default,
        ]);
        $locA = $makeStore('Store A', true);
        $locB = $makeStore('Store B', false);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $locA->id,
        ]);

        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        $product = Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'sold_type' => 'Piece',
            'barcode' => 'RM-'.Str::upper(Str::random(6)),
            'SKU' => 'SKU-RM-'.Str::upper(Str::random(6)),
            'name' => 'Removable Widget',
            'cost' => 100,
            'price' => 150,
            'track_inventory' => true,
        ]);
        $product->activeLocations()->sync([
            $locA->id => ['is_active' => true],
            $locB->id => ['is_active' => true],
        ]);

        return compact('domain', 'locA', 'locB', 'user', 'product');
    }

    private function destroyUrl(array $ctx): string
    {
        return route('domains.products.destroy', [
            'domain' => $ctx['domain']->name_slug,
            'product' => $ctx['product']->id,
        ]);
    }

    public function test_removing_from_a_store_without_stock_keeps_it_elsewhere(): void
    {
        $ctx = $this->seedContext();

        $this->actingAs($ctx['user'])
            ->delete($this->destroyUrl($ctx), ['scope' => 'store', 'location_id' => $ctx['locA']->id])
            ->assertRedirect()
            ->assertSessionHas('success', 'Removable Widget was removed from Store A.');

        $product = $ctx['product']->fresh();
        $this->assertNotNull($product, 'the product still exists');
        $this->assertFalse($product->isAvailableAt($ctx['locA']));
        $this->assertTrue($product->isAvailableAt($ctx['locB']));
    }

    public function test_removing_from_a_store_that_still_has_stock_is_refused(): void
    {
        $ctx = $this->seedContext();
        app(InventoryService::class)->receiveInventory(
            [['product_id' => $ctx['product']->id, 'quantity' => 12, 'unit_cost' => 100]],
            $ctx['user'],
            $ctx['locA'],
        );

        $this->actingAs($ctx['user'])
            ->delete($this->destroyUrl($ctx), ['scope' => 'store', 'location_id' => $ctx['locA']->id])
            ->assertRedirect()
            ->assertSessionHas('error', 'Store A still has 12 of Removable Widget in stock. Transfer it or adjust it to 0 first.');

        $this->assertTrue($ctx['product']->fresh()->isAvailableAt($ctx['locA']));
    }

    public function test_the_only_store_cannot_be_removed_so_the_product_is_never_stranded(): void
    {
        $ctx = $this->seedContext();
        $ctx['product']->removeFromLocation($ctx['locB']);

        $this->actingAs($ctx['user'])
            ->delete($this->destroyUrl($ctx), ['scope' => 'store', 'location_id' => $ctx['locA']->id])
            ->assertRedirect()
            ->assertSessionHas('error', 'Store A is the only store with Removable Widget. Delete it everywhere instead.');

        $this->assertTrue($ctx['product']->fresh()->isAvailableAt($ctx['locA']));
    }

    public function test_a_store_of_another_organization_is_not_found(): void
    {
        $ctx = $this->seedContext();
        $other = $this->seedContext();

        $this->actingAs($ctx['user'])
            ->delete($this->destroyUrl($ctx), ['scope' => 'store', 'location_id' => $other['locA']->id])
            ->assertNotFound();
    }

    public function test_deleting_everywhere_removes_the_product(): void
    {
        $ctx = $this->seedContext();

        $this->actingAs($ctx['user'])
            ->delete($this->destroyUrl($ctx), ['scope' => 'all'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Product deleted successfully');

        $this->assertNull($ctx['product']->fresh());
    }
}
