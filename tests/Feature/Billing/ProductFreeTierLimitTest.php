<?php

namespace Tests\Feature\Billing;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Product\ProductSoldType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductFreeTierLimitTest extends TestCase
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

    private function seedOrgContext(int $productCount = 10): array
    {
        $domain = Domain::query()->create([
            'name' => 'Billing Test Org',
            'name_slug' => 'billing-org-'.Str::lower(Str::random(8)),
            'subscription_active' => false,
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store',
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

        ProductSoldType::query()->firstOrCreate(['name' => 'piece']);

        $category = Category::factory()->create([
            'domain' => $domain->name_slug,
        ]);

        Product::factory()->count($productCount)->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'sold_type' => 'piece',
        ]);

        return compact('domain', 'location', 'user', 'category');
    }

    public function test_store_blocks_eleventh_product_without_subscription(): void
    {
        $ctx = $this->seedOrgContext(10);
        $domain = $ctx['domain'];
        $category = $ctx['category'];

        $url = route('domains.products.store', ['domain' => $domain->name_slug]);

        $response = $this->actingAs($ctx['user'])->post($url, [
            'name' => 'Extra Product',
            'sold_type' => 'piece',
            'price' => 99,
            'cost' => 50,
            'category_id' => $category->id,
            'SKU' => 'SKU-EXTRA-'.Str::upper(Str::random(6)),
            'barcode' => Str::random(13),
            'track_inventory' => true,
        ]);

        $response->assertSessionHasErrors('subscription');
        $this->assertSame(10, Product::query()->where('domain', $domain->name_slug)->count());
    }

    public function test_store_allows_product_when_subscription_active(): void
    {
        $ctx = $this->seedOrgContext(10);
        $domain = $ctx['domain'];
        $category = $ctx['category'];

        $domain->update(['subscription_active' => true]);
        $this->assertTrue($domain->fresh()->subscription_active);

        $url = route('domains.products.store', ['domain' => $domain->name_slug]);

        $response = $this->actingAs($ctx['user'])->post($url, [
            'name' => 'Extra Product',
            'sold_type' => 'piece',
            'price' => 99,
            'cost' => 50,
            'category_id' => $category->id,
            'SKU' => 'SKU-EXTRA-'.Str::upper(Str::random(6)),
            'barcode' => Str::random(13),
            'track_inventory' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(11, Product::query()->where('domain', $domain->name_slug)->count());
    }
}
