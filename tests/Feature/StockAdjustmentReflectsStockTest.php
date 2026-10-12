<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Adjustment not reflecting": an approved adjustment must leave the store holding the counted
 * (actual) quantity — including quantities changed on the draft, and when stock moved between
 * making the draft and approving it.
 */
class StockAdjustmentReflectsStockTest extends TestCase
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
     * @return array{domain: Domain, locA: InventoryLocation, locB: InventoryLocation, user: User, product: Product, other: Product}
     */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Adjust Org',
            'name_slug' => 'adjust-org-'.Str::lower(Str::random(8)),
        ]);

        $locA = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => 'AA-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $locB = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store B',
            'code' => 'AB-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $locA->id,
        ]);

        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        $make = fn (string $name) => tap(Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'sold_type' => 'Piece',
            'barcode' => 'ADJ-'.Str::upper(Str::random(8)),
            'SKU' => 'SKU-ADJ-'.Str::upper(Str::random(8)),
            'name' => $name,
            'cost' => 100,
            'price' => 150,
            'track_inventory' => true,
        ]), fn (Product $p) => $p->activeLocations()->sync([$locA->id => ['is_active' => true]]));

        $product = $make('Adjust Widget');
        $other = $make('Adjust Gadget');

        app(InventoryService::class)->receiveInventory([
            ['product_id' => $product->id, 'quantity' => 12, 'unit_cost' => 100],
            ['product_id' => $other->id, 'quantity' => 5, 'unit_cost' => 100],
        ], $user, $locA);

        return compact('domain', 'locA', 'locB', 'user', 'product', 'other');
    }

    private function quantityAt(Product $product, InventoryLocation $location): int
    {
        return (int) ProductInventory::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->value('quantity_on_hand');
    }

    private function draft(array $ctx, int $actual): StockAdjustment
    {
        return app(InventoryService::class)->createStockAdjustment([
            'location_id' => $ctx['locA']->id,
            'domain' => $ctx['domain']->name_slug,
            'type' => 'recount',
            'reason' => 'physical_count',
        ], [
            ['product_id' => $ctx['product']->id, 'actual_quantity' => $actual],
        ], $ctx['user']);
    }

    public function test_approving_sets_the_store_quantity_to_the_counted_quantity(): void
    {
        $ctx = $this->seedContext();
        $adjustment = $this->draft($ctx, 10);

        $this->assertSame(12, $this->quantityAt($ctx['product'], $ctx['locA']), 'Drafts must not touch stock.');

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.inventory.adjustments.approve', [
                'domain' => $ctx['domain']->name_slug,
                'adjustment' => $adjustment->id,
            ]))
            ->assertOk();

        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertSame(10, $this->quantityAt($ctx['product'], $ctx['locA']));
    }

    public function test_edited_draft_quantities_are_saved_and_applied(): void
    {
        $ctx = $this->seedContext();
        $adjustment = $this->draft($ctx, 10);
        $item = $adjustment->items()->first();

        $this->actingAs($ctx['user'])
            ->putJson(route('domains.inventory.adjustments.update', [
                'domain' => $ctx['domain']->name_slug,
                'adjustment' => $adjustment->id,
            ]), [
                'type' => 'recount',
                'reason' => 'physical_count',
                'description' => 'Recounted',
                'items' => [
                    ['id' => $item->id, 'product_id' => $ctx['product']->id, 'actual_quantity' => 7],
                    ['product_id' => $ctx['other']->id, 'actual_quantity' => 8],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $adjustment->refresh();
        $this->assertSame('Recounted', $adjustment->description);
        $this->assertCount(2, $adjustment->items);
        $this->assertSame(7, $adjustment->items->firstWhere('product_id', $ctx['product']->id)->actual_quantity);
        $this->assertSame(5, $adjustment->items->firstWhere('product_id', $ctx['other']->id)->system_quantity);

        $adjustment->approve($ctx['user']);

        $this->assertSame(7, $this->quantityAt($ctx['product'], $ctx['locA']));
        $this->assertSame(8, $this->quantityAt($ctx['other'], $ctx['locA']));
    }

    public function test_items_left_out_of_an_edit_are_removed(): void
    {
        $ctx = $this->seedContext();
        $adjustment = $this->draft($ctx, 10);

        $this->actingAs($ctx['user'])
            ->putJson(route('domains.inventory.adjustments.update', [
                'domain' => $ctx['domain']->name_slug,
                'adjustment' => $adjustment->id,
            ]), [
                'type' => 'recount',
                'reason' => 'physical_count',
                'items' => [
                    ['product_id' => $ctx['other']->id, 'actual_quantity' => 3],
                ],
            ])
            ->assertOk();

        $this->assertSame(
            [$ctx['other']->id],
            $adjustment->items()->pluck('product_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_stock_moving_before_approval_still_ends_at_the_counted_quantity(): void
    {
        $ctx = $this->seedContext();
        $adjustment = $this->draft($ctx, 10); // counted 10 while the system said 12

        // 3 leave the store before the adjustment is approved.
        app(InventoryService::class)->transferInventory($ctx['product'], $ctx['locA'], $ctx['locB'], 3, $ctx['user']);
        $this->assertSame(9, $this->quantityAt($ctx['product'], $ctx['locA']));

        $adjustment->approve($ctx['user']);

        $this->assertSame(10, $this->quantityAt($ctx['product'], $ctx['locA']));
        $item = $adjustment->items()->first();
        $this->assertSame(9, $item->system_quantity);
        $this->assertSame(1, $item->adjustment_quantity);
    }
}
