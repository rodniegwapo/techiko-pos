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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductAttachToStoreTest extends TestCase
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
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Attach Org',
            'name_slug' => 'attach-org-'.Str::lower(Str::random(8)),
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

    /**
     * @return list<int|string>
     */
    private function assignableIdsFromResponse(TestResponse $response): array
    {
        $wrapped = $response->json('data.data');
        if (is_array($wrapped)) {
            return collect($wrapped)->pluck('id')->all();
        }
        $flat = $response->json('data');
        $this->assertIsArray($flat);

        return collect($flat)->pluck('id')->all();
    }

    public function test_assignable_lists_products_not_active_at_requested_store(): void
    {
        $ctx = $this->seedContext();

        $p = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ATTACH-ONLY-A',
            'SKU' => 'SKU-AONLY',
            'name' => 'Attachable Widget',
        ]);
        $p->activeLocations()->sync([$ctx['locA']->id => ['is_active' => true]]);

        $url = route('domains.products.assignable', ['domain' => $ctx['domain']->name_slug]);
        $query = http_build_query(['location_id' => $ctx['locB']->id]);

        $response = $this->actingAs($ctx['user'])->getJson($url.'?'.$query);
        $response->assertOk();
        $this->assertContains($p->id, $this->assignableIdsFromResponse($response));
    }

    public function test_assignable_excludes_never_attached_products(): void
    {
        $ctx = $this->seedContext();

        $orphan = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ORPHAN-NO-PVT',
            'SKU' => 'SKU-ORPHAN',
            'name' => 'Never Attached Catalog Row',
        ]);

        $url = route('domains.products.assignable', ['domain' => $ctx['domain']->name_slug]);
        $query = http_build_query(['location_id' => $ctx['locB']->id]);

        $response = $this->actingAs($ctx['user'])->getJson($url.'?'.$query);
        $response->assertOk();
        $this->assertNotContains($orphan->id, $this->assignableIdsFromResponse($response));
    }

    public function test_assignable_search_excludes_never_attached_even_when_name_matches(): void
    {
        $ctx = $this->seedContext();

        $needle = 'MatchTokXy7';

        $orphan = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ORPHAN-SEARCH',
            'SKU' => 'SKU-O-SEARCH',
            'name' => "Orphan label {$needle} zzz",
        ]);

        $atOther = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ELSEWHERE-SRCH',
            'SKU' => 'SKU-E-SEARCH',
            'name' => "Stocked {$needle} elsewhere",
        ]);
        $atOther->activeLocations()->sync([$ctx['locA']->id => ['is_active' => true]]);

        $url = route('domains.products.assignable', ['domain' => $ctx['domain']->name_slug]);
        $query = http_build_query([
            'location_id' => $ctx['locB']->id,
            'search' => $needle,
        ]);

        $response = $this->actingAs($ctx['user'])->getJson($url.'?'.$query);
        $response->assertOk();
        $ids = $this->assignableIdsFromResponse($response);
        $this->assertNotContains($orphan->id, $ids);
        $this->assertContains($atOther->id, $ids);
    }

    public function test_attach_adds_pivot_and_inventory_row(): void
    {
        $ctx = $this->seedContext();

        $p = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ATTACH-PIVOT',
            'SKU' => 'SKU-PIVOT',
            'name' => 'Pivot Product',
            'track_inventory' => true,
        ]);
        $p->activeLocations()->sync([$ctx['locA']->id => ['is_active' => true]]);

        $attachUrl = route('domains.products.attach-location', [
            'domain' => $ctx['domain']->name_slug,
            'product' => $p->id,
        ]);

        $res = $this->actingAs($ctx['user'])->postJson($attachUrl, [
            'location_id' => $ctx['locB']->id,
        ]);
        $res->assertOk()->assertExactJson([
            'success' => true,
            'already_attached' => false,
        ]);

        $p->refresh();
        $this->assertTrue($p->isAvailableAt($ctx['locB']));

        $this->assertDatabaseHas('product_inventory', [
            'product_id' => $p->id,
            'location_id' => $ctx['locB']->id,
            'quantity_on_hand' => 0,
            'quantity_available' => 0,
        ]);
    }

    public function test_assignable_excludes_product_after_attach(): void
    {
        $ctx = $this->seedContext();

        $p = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ATTACH-Then-Hide',
            'SKU' => 'SKU-HIDE',
            'name' => 'Then Hidden',
        ]);
        $p->activeLocations()->sync([$ctx['locA']->id => ['is_active' => true]]);

        $attachUrl = route('domains.products.attach-location', [
            'domain' => $ctx['domain']->name_slug,
            'product' => $p->id,
        ]);
        $this->actingAs($ctx['user'])->postJson($attachUrl, [
            'location_id' => $ctx['locB']->id,
        ])->assertOk();

        $listUrl = route('domains.products.assignable', ['domain' => $ctx['domain']->name_slug]);
        $query = http_build_query(['location_id' => $ctx['locB']->id]);
        $response = $this->actingAs($ctx['user'])->getJson($listUrl.'?'.$query);
        $response->assertOk();
        $this->assertNotContains($p->id, $this->assignableIdsFromResponse($response));
    }

    public function test_attach_is_idempotent_when_already_at_store(): void
    {
        $ctx = $this->seedContext();

        $p = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'ATTACH-IDEM',
            'SKU' => 'SKU-IDEM',
            'name' => 'Idempotent Product',
        ]);
        $p->activeLocations()->sync([$ctx['locB']->id => ['is_active' => true]]);

        $attachUrl = route('domains.products.attach-location', [
            'domain' => $ctx['domain']->name_slug,
            'product' => $p->id,
        ]);

        $res = $this->actingAs($ctx['user'])->postJson($attachUrl, [
            'location_id' => $ctx['locB']->id,
        ]);
        $res->assertOk()->assertExactJson([
            'success' => true,
            'already_attached' => true,
        ]);
    }

    public function test_attach_rejects_when_another_product_same_name_at_store(): void
    {
        $ctx = $this->seedContext();

        $sharedName = 'Shared Display Name';

        $blocker = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'BLOCKER-1',
            'SKU' => 'SKU-BLOCKER',
            'name' => $sharedName,
        ]);
        $blocker->activeLocations()->sync([$ctx['locB']->id => ['is_active' => true]]);

        $p = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'barcode' => 'CONFLICT-1',
            'SKU' => 'SKU-CONFLICT',
            'name' => $sharedName,
        ]);
        $p->activeLocations()->sync([$ctx['locA']->id => ['is_active' => true]]);

        $attachUrl = route('domains.products.attach-location', [
            'domain' => $ctx['domain']->name_slug,
            'product' => $p->id,
        ]);

        $this->actingAs($ctx['user'])->postJson($attachUrl, [
            'location_id' => $ctx['locB']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
    }
}
