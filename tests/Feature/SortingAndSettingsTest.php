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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Sorting the Products and Inventory lists, and the organization's currency in Settings. */
class SortingAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create(['name' => 'Sort Org', 'name_slug' => 'sort-org-'.Str::lower(Str::random(6))]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $this->admin = User::factory()->create([
            'domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id,
        ]);

        $drinks = Category::factory()->create(['domain' => $this->domain->name_slug, 'name' => 'Drinks']);
        $bakery = Category::factory()->create(['domain' => $this->domain->name_slug, 'name' => 'Bakery']);
        foreach ([['Cola', $drinks, 30, 5], ['Bread', $bakery, 50, 20], ['Apple Juice', $drinks, 40, 1]] as [$name, $category, $price, $qty]) {
            $product = Product::factory()->create([
                'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'name' => $name,
                'price' => $price, 'track_inventory' => true,
            ]);
            $product->activeLocations()->attach($this->store->id, ['is_active' => true]);
            ProductInventory::query()->create([
                'product_id' => $product->id, 'location_id' => $this->store->id,
                'quantity_on_hand' => $qty, 'quantity_reserved' => 0, 'quantity_available' => $qty,
            ]);
        }
    }

    private function productNames(string $sort): array
    {
        $names = [];
        $this->actingAs($this->admin)
            ->get(route('domains.products.index', ['domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'sort' => $sort]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $names = collect($page->toArray()['props']['items']['data'])->pluck('name')->all();
            });

        return $names;
    }

    private function inventoryNames(string $sort): array
    {
        $names = [];
        $this->actingAs($this->admin)
            ->get(route('domains.inventory.products', ['domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'sort' => $sort]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $names = collect($page->toArray()['props']['inventories']['data'])->pluck('product.name')->all();
            });

        return $names;
    }

    public function test_products_sort_by_name_category_price_and_quantity(): void
    {
        $this->assertSame(['Cola', 'Bread', 'Apple Juice'], $this->productNames('name_desc'));
        $this->assertSame(['Bread', 'Apple Juice', 'Cola'], $this->productNames('category'));
        $this->assertSame(['Bread', 'Apple Juice', 'Cola'], $this->productNames('price_desc'));
        $this->assertSame(['Bread', 'Cola', 'Apple Juice'], $this->productNames('qty_desc'));
    }

    public function test_inventory_sorts_too(): void
    {
        $this->assertSame(['Apple Juice', 'Cola', 'Bread'], $this->inventoryNames(''));
        $this->assertSame(['Apple Juice', 'Bread', 'Cola'], $this->inventoryNames('name'));
        $this->assertSame(['Bread', 'Apple Juice', 'Cola'], $this->inventoryNames('category'));
        $this->assertSame(['Bread', 'Cola', 'Apple Juice'], $this->inventoryNames('qty_desc'));
    }

    public function test_settings_show_the_account_and_save_the_currency(): void
    {
        $this->actingAs($this->admin)
            ->get(route('domains.settings.index', ['domain' => $this->domain->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.name', 'Sort Org')
                ->where('account.locations', 1)
                ->where('currency.code', 'PHP'));

        $this->actingAs($this->admin)
            ->patch(route('domains.settings.update', ['domain' => $this->domain->name_slug]), ['currency_code' => 'USD'])
            ->assertSessionHasNoErrors();
        $this->assertSame('USD', $this->domain->fresh()->currency_code);

        $this->actingAs($this->admin)
            ->patch(route('domains.settings.update', ['domain' => $this->domain->name_slug]), ['currency_code' => 'XYZ'])
            ->assertSessionHasErrors('currency_code');
    }
}
