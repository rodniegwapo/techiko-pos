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
use App\Services\InventoryService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Moving several products between stores at once: all of them move, or none do. */
class InventoryTransferManyTest extends TestCase
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

    /** @return array{domain: Domain, from: InventoryLocation, to: InventoryLocation, user: User, cups: Product, lids: Product} */
    private function seedContext(string $slugPrefix = 'xfer'): array
    {
        $domain = Domain::query()->create([
            'name' => 'Transfer Many Org',
            'name_slug' => $slugPrefix.'-'.Str::lower(Str::random(8)),
        ]);

        $store = fn (string $name, bool $default) => InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => $name,
            'code' => 'TM-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => $default,
        ]);
        $from = $store('Main Store', true);
        $to = $store('Branch', false);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $from->id,
        ]);
        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        $product = function (string $name, int $qty) use ($domain, $category, $from, $user) {
            $p = Product::factory()->create([
                'domain' => $domain->name_slug,
                'category_id' => $category->id,
                'sold_type' => 'Piece',
                'name' => $name,
                'barcode' => 'TM-'.Str::upper(Str::random(8)),
                'SKU' => 'SKU-TM-'.Str::upper(Str::random(8)),
                'track_inventory' => true,
            ]);
            $p->activeLocations()->sync([$from->id => ['is_active' => true]]);
            app(InventoryService::class)->receiveInventory(
                [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => 5]],
                $user,
                $from,
            );

            return $p;
        };

        return [
            'domain' => $domain,
            'from' => $from,
            'to' => $to,
            'user' => $user,
            'cups' => $product('Cups', 10),
            'lids' => $product('Lids', 4),
        ];
    }

    private function qty(Product $product, InventoryLocation $location): int
    {
        return (int) ProductInventory::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->value('quantity_on_hand');
    }

    private function transfer(array $ctx, array $body)
    {
        return $this->actingAs($ctx['user'])->postJson('/inventory/transfer', [
            'from_location_id' => $ctx['from']->id,
            'to_location_id' => $ctx['to']->id,
            ...$body,
        ]);
    }

    public function test_several_products_move_together(): void
    {
        $ctx = $this->seedContext();

        $this->transfer($ctx, ['items' => [
            ['product_id' => $ctx['cups']->id, 'quantity' => 6],
            ['product_id' => $ctx['lids']->id, 'quantity' => 4],
        ]])->assertOk()->assertJsonPath('success', true);

        $this->assertSame([4, 6], [$this->qty($ctx['cups'], $ctx['from']), $this->qty($ctx['cups'], $ctx['to'])]);
        $this->assertSame([0, 4], [$this->qty($ctx['lids'], $ctx['from']), $this->qty($ctx['lids'], $ctx['to'])]);
        $this->assertTrue($ctx['cups']->fresh()->isAvailableAt($ctx['to']));
        $this->assertTrue($ctx['lids']->fresh()->isAvailableAt($ctx['to']));
    }

    public function test_one_short_row_moves_nothing_and_names_that_row(): void
    {
        $ctx = $this->seedContext();

        $this->transfer($ctx, ['items' => [
            ['product_id' => $ctx['cups']->id, 'quantity' => 6],
            ['product_id' => $ctx['lids']->id, 'quantity' => 9],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['items.1.quantity' => ['Only 4 available at Main Store']]);

        $this->assertSame(10, $this->qty($ctx['cups'], $ctx['from']));
        $this->assertSame(0, $this->qty($ctx['cups'], $ctx['to']));
        $this->assertSame(4, $this->qty($ctx['lids'], $ctx['from']));
    }

    public function test_the_same_product_twice_is_refused(): void
    {
        $ctx = $this->seedContext();

        $this->transfer($ctx, ['items' => [
            ['product_id' => $ctx['cups']->id, 'quantity' => 1],
            ['product_id' => $ctx['cups']->id, 'quantity' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('items.1.product_id');
    }

    public function test_the_single_product_form_still_works(): void
    {
        $ctx = $this->seedContext();

        $this->transfer($ctx, ['product_id' => $ctx['cups']->id, 'quantity' => 3])->assertOk();
        $this->assertSame(3, $this->qty($ctx['cups'], $ctx['to']));

        $this->transfer($ctx, ['product_id' => $ctx['lids']->id, 'quantity' => 50])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Only 4 units available at source location');
    }

    public function test_another_organizations_product_is_refused(): void
    {
        $ctx = $this->seedContext();
        $other = $this->seedContext('other');

        $this->transfer($ctx, ['items' => [
            ['product_id' => $ctx['cups']->id, 'quantity' => 1],
            ['product_id' => $other['cups']->id, 'quantity' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('items.1.product_id');

        $this->assertSame(10, $this->qty($ctx['cups'], $ctx['from']));
    }

    public function test_the_organization_route_moves_several_products(): void
    {
        $ctx = $this->seedContext();

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.inventory.transfer', ['domain' => $ctx['domain']->name_slug]), [
                'from_location_id' => $ctx['from']->id,
                'to_location_id' => $ctx['to']->id,
                'items' => [
                    ['product_id' => $ctx['cups']->id, 'quantity' => 2],
                    ['product_id' => $ctx['lids']->id, 'quantity' => 2],
                ],
            ])
            ->assertOk();

        $this->assertSame(2, $this->qty($ctx['lids'], $ctx['to']));
    }
}
