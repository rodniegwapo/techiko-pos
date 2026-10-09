<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\ModifierGroup;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Product modifiers (add-ons): option groups such as Size or Add-ons, attached to products and picked
 * when they're rung up. The line is priced on the server; different options make different lines.
 */
class ProductModifiersTest extends TestCase
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

    /** @return array{domain: Domain, store: InventoryLocation, user: User, latte: Product} */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Cafe Org',
            'name_slug' => 'mod-org-'.Str::lower(Str::random(8)),
        ]);

        $store = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => 'MD-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $store->id,
        ]);

        $latte = Product::factory()->create([
            'domain' => $domain->name_slug,
            'name' => 'Latte',
            'category_id' => Category::factory()->create(['domain' => $domain->name_slug])->id,
            'sold_type' => 'Piece',
            'track_inventory' => true,
            'price' => 120,
            'cost' => 40,
        ]);
        $latte->activeLocations()->attach($store->id, ['is_active' => true]);
        ProductInventory::query()->create([
            'product_id' => $latte->id,
            'location_id' => $store->id,
            'quantity_on_hand' => 50,
            'quantity_reserved' => 0,
            'quantity_available' => 50,
        ]);

        return compact('domain', 'store', 'user', 'latte');
    }

    private function url(array $ctx, string $name, array $params = []): string
    {
        return route($name, ['domain' => $ctx['domain']->name_slug, ...$params]);
    }

    private function createGroup(array $ctx, array $body)
    {
        return $this->actingAs($ctx['user'])
            ->postJson($this->url($ctx, 'domains.products.modifier-groups.store'), $body);
    }

    /** Size (required, one of Small / Medium +10 / Large +20) and Add-ons (up to 2) on the latte. */
    private function seedGroups(array $ctx): array
    {
        $this->createGroup($ctx, [
            'name' => 'Size',
            'selection' => 'single',
            'is_required' => true,
            'modifiers' => [
                ['name' => 'Small', 'price_delta' => 0],
                ['name' => 'Medium', 'price_delta' => 10],
                ['name' => 'Large', 'price_delta' => 20, 'cost_delta' => 5],
            ],
            'product_ids' => [$ctx['latte']->id],
        ])->assertOk();

        $this->createGroup($ctx, [
            'name' => 'Add-ons',
            'selection' => 'multiple',
            'max_select' => 2,
            'modifiers' => [
                ['name' => 'Extra shot', 'price_delta' => 25],
                ['name' => 'Oat milk', 'price_delta' => 30],
                ['name' => 'No sugar', 'price_delta' => 0],
            ],
            'product_ids' => [$ctx['latte']->id],
        ])->assertOk();

        $options = ModifierGroup::with('modifiers')->get()->flatMap->modifiers->keyBy('name');

        return $options->map->id->all();
    }

    private function addToCart(array $ctx, array $body)
    {
        return $this->actingAs($ctx['user'])->postJson(
            $this->url($ctx, 'domains.users.sales.cart.add', ['user' => $ctx['user']->id]),
            ['product_id' => $ctx['latte']->id, 'quantity' => 1, ...$body]
        );
    }

    public function test_a_group_is_saved_with_its_options_and_products(): void
    {
        $ctx = $this->seedContext();
        $ids = $this->seedGroups($ctx);

        $size = ModifierGroup::where('name', 'Size')->with(['modifiers', 'products'])->sole();
        $this->assertSame($ctx['domain']->name_slug, $size->domain);
        $this->assertSame(['Small', 'Medium', 'Large'], $size->modifiers->pluck('name')->all());
        $this->assertSame([$ctx['latte']->id], $size->products->pluck('id')->all());
        $this->assertCount(6, $ids);
    }

    public function test_editing_a_group_updates_adds_and_removes_options(): void
    {
        $ctx = $this->seedContext();
        $this->seedGroups($ctx);
        $size = ModifierGroup::where('name', 'Size')->with('modifiers')->sole();
        [$small, $medium] = $size->modifiers->all();

        $this->actingAs($ctx['user'])->putJson($this->url($ctx, 'domains.products.modifier-groups.update', ['modifier_group' => $size->id]), [
            'name' => 'Cup size',
            'selection' => 'single',
            'is_required' => true,
            'modifiers' => [
                ['id' => $small->id, 'name' => 'Small', 'price_delta' => 0],
                ['id' => $medium->id, 'name' => 'Regular', 'price_delta' => 15],
                ['name' => 'Venti', 'price_delta' => 40],
            ],
            'product_ids' => [$ctx['latte']->id],
        ])->assertOk();

        $size->refresh()->load('modifiers');
        $this->assertSame('Cup size', $size->name);
        $this->assertSame(['Small', 'Regular', 'Venti'], $size->modifiers->pluck('name')->all());
        $this->assertSame($medium->id, $size->modifiers[1]->id, 'kept, renamed and repriced');
        $this->assertEquals(15, (float) $size->modifiers[1]->price_delta);
    }

    public function test_a_group_cannot_take_another_organizations_products_or_options(): void
    {
        $ctx = $this->seedContext();
        $other = $this->seedContext();
        $this->seedGroups($other);
        $foreignOption = ModifierGroup::where('domain', $other['domain']->name_slug)->first()->modifiers()->first();

        $this->createGroup($ctx, [
            'name' => 'Size',
            'selection' => 'single',
            'modifiers' => [['name' => 'Small']],
            'product_ids' => [$other['latte']->id],
        ])->assertStatus(422)->assertJsonValidationErrors('product_ids.0');

        $mine = ModifierGroup::forDomain($ctx['domain']->name_slug)->count();
        $this->assertSame(0, $mine);

        $this->createGroup($ctx, ['name' => 'Milk', 'selection' => 'single', 'modifiers' => [['name' => 'Oat']]])->assertOk();
        $milk = ModifierGroup::forDomain($ctx['domain']->name_slug)->sole();

        $this->actingAs($ctx['user'])->putJson($this->url($ctx, 'domains.products.modifier-groups.update', ['modifier_group' => $milk->id]), [
            'name' => 'Milk',
            'selection' => 'single',
            'modifiers' => [['id' => $foreignOption->id, 'name' => 'Stolen']],
        ])->assertStatus(422)->assertJsonValidationErrors('modifiers.0.id');

        $this->actingAs($ctx['user'])
            ->deleteJson($this->url($ctx, 'domains.products.modifier-groups.destroy', ['modifier_group' => ModifierGroup::where('domain', $other['domain']->name_slug)->first()->id]))
            ->assertNotFound();
    }

    public function test_the_line_is_priced_with_its_options(): void
    {
        $ctx = $this->seedContext();
        $ids = $this->seedGroups($ctx);

        $this->addToCart($ctx, ['modifier_ids' => [$ids['Large'], $ids['Extra shot']], 'notes' => 'less ice'])->assertOk();

        $line = SaleItem::with('modifiers')->sole();
        $this->assertEquals(165, (float) $line->unit_price, '120 + 20 + 25');
        $this->assertEquals(45, (float) $line->unit_cost, '40 + 5 for the large cup');
        $this->assertSame('less ice', $line->notes);
        $this->assertSame(['Large', 'Extra shot'], $line->modifiers->pluck('name')->all());
        $this->assertSame(['Size', 'Add-ons'], $line->modifiers->pluck('group_name')->all());
        $this->assertEquals(165, (float) Sale::sole()->grand_total);
    }

    public function test_same_options_add_up_and_different_options_are_their_own_line(): void
    {
        $ctx = $this->seedContext();
        $ids = $this->seedGroups($ctx);

        $this->addToCart($ctx, ['modifier_ids' => [$ids['Medium'], $ids['Oat milk']]])->assertOk();
        // Same options in another order: the same line.
        $this->addToCart($ctx, ['modifier_ids' => [$ids['Oat milk'], $ids['Medium']]])->assertOk();
        $this->addToCart($ctx, ['modifier_ids' => [$ids['Small']]])->assertOk();

        $lines = SaleItem::orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(2, (int) $lines[0]->quantity);
        $this->assertEquals(160, (float) $lines[0]->unit_price);
        $this->assertEquals(120, (float) $lines[1]->unit_price);
    }

    public function test_options_must_be_offered_required_and_within_the_limit(): void
    {
        $ctx = $this->seedContext();
        $ids = $this->seedGroups($ctx);

        $this->addToCart($ctx, [])->assertStatus(422)->assertJsonValidationErrors('modifier_ids');
        $this->addToCart($ctx, ['modifier_ids' => [$ids['Small'], $ids['Large']]])
            ->assertStatus(422)->assertJsonValidationErrors('modifier_ids');
        $this->addToCart($ctx, ['modifier_ids' => [$ids['Small'], $ids['Extra shot'], $ids['Oat milk'], $ids['No sugar']]])
            ->assertStatus(422)->assertJsonValidationErrors('modifier_ids');

        $plain = Product::factory()->create(['domain' => $ctx['domain']->name_slug, 'price' => 50, 'sold_type' => 'Piece', 'track_inventory' => false]);
        $this->actingAs($ctx['user'])->postJson(
            $this->url($ctx, 'domains.users.sales.cart.add', ['user' => $ctx['user']->id]),
            ['product_id' => $plain->id, 'quantity' => 1, 'modifier_ids' => [$ids['Large']]]
        )->assertStatus(422)->assertJsonValidationErrors('modifier_ids');

        $this->assertSame(0, SaleItem::count());
    }

    public function test_quantity_and_removal_go_by_the_line(): void
    {
        $ctx = $this->seedContext();
        $ids = $this->seedGroups($ctx);
        $this->addToCart($ctx, ['modifier_ids' => [$ids['Small']]])->assertOk();
        $this->addToCart($ctx, ['modifier_ids' => [$ids['Large']]])->assertOk();
        [$small, $large] = SaleItem::orderBy('id')->get()->all();

        $this->actingAs($ctx['user'])->patchJson(
            $this->url($ctx, 'domains.users.sales.cart.update-quantity', ['user' => $ctx['user']->id]),
            ['product_id' => $ctx['latte']->id, 'sale_item_id' => $large->id, 'quantity' => 3]
        )->assertOk();

        $this->assertSame(1, (int) $small->fresh()->quantity);
        $this->assertSame(3, (int) $large->fresh()->quantity);

        $this->actingAs($ctx['user'])->deleteJson(
            $this->url($ctx, 'domains.users.sales.cart.remove', ['user' => $ctx['user']->id]),
            ['product_id' => $ctx['latte']->id, 'sale_item_id' => $small->id]
        )->assertOk();

        $this->assertNull(SaleItem::find($small->id));
        $this->assertNotNull(SaleItem::find($large->id));
    }

    public function test_the_pos_catalog_lists_a_products_groups_and_options(): void
    {
        $ctx = $this->seedContext();
        $this->seedGroups($ctx);

        $this->actingAs($ctx['user'])
            ->getJson($this->url($ctx, 'domains.sales.products', ['location_id' => $ctx['store']->id]))
            ->assertOk()
            ->assertJsonPath('data.0.modifier_groups.0.name', 'Size')
            ->assertJsonCount(3, 'data.0.modifier_groups.0.active_modifiers');
    }

    public function test_an_offline_line_with_options_is_priced_on_the_server(): void
    {
        $ctx = $this->seedContext();
        $ids = $this->seedGroups($ctx);

        $response = $this->actingAs($ctx['user'])->postJson($this->url($ctx, 'domains.sales.offline-sync'), [
            'sales' => [[
                'client_mutation_id' => (string) Str::uuid(),
                'payload' => [
                    // The client's price is not trusted for a line with options.
                    'items' => [['product_id' => $ctx['latte']->id, 'quantity' => 2, 'unit_price' => 1, 'modifier_ids' => [$ids['Medium']]]],
                    'payment_method' => 'cash',
                    'location_id' => $ctx['store']->id,
                    'cashier_user_id' => $ctx['user']->id,
                ],
            ]],
        ])->assertOk();

        $saleId = collect($response->json('results'))->first()['sale_id'] ?? null;
        $this->assertNotNull($saleId, json_encode($response->json()));

        $line = Sale::findOrFail($saleId)->saleItems()->with('modifiers')->sole();
        $this->assertEquals(130, (float) $line->unit_price);
        $this->assertSame(['Medium'], $line->modifiers->pluck('name')->all());
        $this->assertSame(48, (int) ProductInventory::where('product_id', $ctx['latte']->id)->value('quantity_on_hand'));
    }

    public function test_sales_history_shows_a_lines_options(): void
    {
        $ctx = $this->seedContext();
        $ids = $this->seedGroups($ctx);
        $this->addToCart($ctx, ['modifier_ids' => [$ids['Large']], 'notes' => 'hot'])->assertOk();
        $sale = Sale::sole();
        $this->actingAs($ctx['user'])->postJson(
            $this->url($ctx, 'domains.sales.payment.store', ['sale' => $sale->id]),
            ['payment_method' => 'cash']
        )->assertOk();

        $this->actingAs($ctx['user'])
            ->getJson($this->url($ctx, 'domains.sales-history.show', ['sale' => $sale->id]))
            ->assertOk()
            ->assertJsonPath('items.0.modifiers.0.name', 'Large')
            ->assertJsonPath('items.0.modifiers.0.price_delta', 20)
            ->assertJsonPath('items.0.notes', 'hot');
    }

    public function test_the_product_form_attaches_and_clears_groups(): void
    {
        $ctx = $this->seedContext();
        $this->seedGroups($ctx);
        $groupIds = ModifierGroup::pluck('id')->all();
        $product = $ctx['latte'];

        $form = fn (array $extra) => [
            'name' => 'Latte',
            'sold_type' => 'Piece',
            'price' => 120,
            'track_inventory' => true,
            ...$extra,
        ];

        $this->actingAs($ctx['user'])->put(
            $this->url($ctx, 'domains.products.update', ['product' => $product->id]),
            $form(['modifier_groups_present' => true, 'modifier_group_ids' => [$groupIds[1]]])
        )->assertRedirect();
        $this->assertSame([$groupIds[1]], $product->modifierGroups()->pluck('modifier_groups.id')->all());

        // A caller that doesn't send the field leaves the groups alone…
        $this->actingAs($ctx['user'])->put(
            $this->url($ctx, 'domains.products.update', ['product' => $product->id]),
            $form([])
        )->assertRedirect();
        $this->assertCount(1, $product->modifierGroups()->get());

        // …and the form sending none clears them.
        $this->actingAs($ctx['user'])->put(
            $this->url($ctx, 'domains.products.update', ['product' => $product->id]),
            $form(['modifier_groups_present' => true])
        )->assertRedirect();
        $this->assertCount(0, $product->modifierGroups()->get());
    }
}
