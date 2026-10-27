<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\KitchenTicket;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserPin;
use App\Models\VoidLog;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Concerns\OpensTodaysShift;
use Tests\TestCase;

/**
 * Dine-in tabs: open on a table or under a name, add to them through the usual cart, send only
 * what is new to the kitchen, move, merge, split, cancel and pay one tab at a time.
 */
class DineInTabsTest extends TestCase
{
    use OpensTodaysShift;
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private User $cashier;

    private User $manager;

    private Product $adobo;

    private Product $rice;

    private DiningTable $t1;

    private DiningTable $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openTodaysShiftForNewStores();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
        $this->seed(ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create(['name' => 'Kainan', 'name_slug' => 'kainan-'.Str::lower(Str::random(6))]);
        $slug = $this->domain->name_slug;
        $this->store = InventoryLocation::query()->create([
            'domain' => $slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true, 'dine_in' => true,
        ]);
        $this->cashier = User::factory()->create(['domain' => $slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $this->manager = User::factory()->create(['domain' => $slug, 'location_id' => $this->store->id]);
        $this->manager->assignRole(Role::findOrCreate('manager', 'web'));
        UserPin::query()->create(['user_id' => $this->manager->id, 'pin_code' => Hash::make('2468'), 'active' => true]);

        $category = Category::factory()->create(['domain' => $slug]);
        $make = function (string $name, float $price, float $stock) use ($category, $slug) {
            $p = Product::factory()->create([
                'domain' => $slug, 'category_id' => $category->id, 'name' => $name,
                'sold_type' => 'Piece', 'track_inventory' => true, 'price' => $price, 'cost' => $price / 2,
            ]);
            $p->activeLocations()->attach($this->store->id, ['is_active' => true]);
            ProductInventory::query()->create([
                'product_id' => $p->id, 'location_id' => $this->store->id,
                'quantity_on_hand' => $stock, 'quantity_reserved' => 0, 'quantity_available' => $stock,
            ]);

            return $p;
        };
        $this->adobo = $make('Adobo', 120, 50);
        $this->rice = $make('Rice', 20, 50);

        $this->t1 = DiningTable::query()->create(['domain' => $slug, 'location_id' => $this->store->id, 'name' => 'T1', 'seats' => 4]);
        $this->t2 = DiningTable::query()->create(['domain' => $slug, 'location_id' => $this->store->id, 'name' => 'T2', 'seats' => 2]);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    /** The till has this tab open (what tabs.select remembers). */
    private function onTab(?Sale $tab)
    {
        return $this->actingAs($this->cashier)->withSession($tab ? ['pos.tab.'.$this->domain->name_slug => $tab->id] : []);
    }

    private function open(array $data): Sale
    {
        $id = $this->actingAs($this->cashier)->postJson($this->url('tabs.store'), $data)->assertCreated()->json('tab.id');

        return Sale::query()->findOrFail($id);
    }

    private function add(Sale $tab, Product $product, float $quantity)
    {
        return $this->onTab($tab)->postJson(
            $this->url('users.sales.cart.add', ['user' => $this->cashier->id]),
            ['product_id' => $product->id, 'quantity' => $quantity],
        );
    }

    public function test_tabs_on_two_tables_keep_their_own_orders_apart_from_the_cashiers_cart(): void
    {
        $one = $this->open(['dining_table_id' => $this->t1->id, 'guests' => 3]);
        $two = $this->open(['dining_table_id' => $this->t2->id]);

        $this->add($one, $this->adobo, 2)->assertOk()->assertJsonPath('sale.id', $one->id);
        $this->add($two, $this->rice, 1)->assertOk()->assertJsonPath('sale.id', $two->id);

        $this->assertEquals(240, (float) $one->fresh()->grand_total);
        $this->assertEquals(20, (float) $two->fresh()->grand_total);
        $this->assertTrue($one->fresh()->is_tab);

        // Off the tab, the cart is the cashier's own again, not a tab they opened.
        $this->onTab(null)->getJson($this->url('users.sales.current-pending', ['user' => $this->cashier->id]))
            ->assertOk()->assertJsonPath('sale', null);
        $own = $this->onTab(null)->postJson($this->url('users.sales.cart.add', ['user' => $this->cashier->id]), [
            'product_id' => $this->rice->id, 'quantity' => 1,
        ])->assertOk()->json('sale.id');
        $this->assertNotContains($own, [$one->id, $two->id]);
        $this->assertFalse(Sale::query()->find($own)->is_tab);

        $this->actingAs($this->cashier)->getJson($this->url('tabs.index'))
            ->assertOk()
            ->assertJsonCount(2, 'tabs')
            ->assertJsonPath('tables.0.name', 'T1')
            ->assertJsonPath('tables.0.tabs.0.id', $one->id)
            ->assertJsonPath('tables.0.tabs.0.total', 240)
            ->assertJsonPath('tables.0.tabs.0.guests', 3);
    }

    public function test_the_kitchen_gets_only_what_is_new(): void
    {
        $tab = $this->open(['dining_table_id' => $this->t1->id]);
        $this->add($tab, $this->adobo, 2);
        $this->add($tab, $this->rice, 2);

        $this->onTab($tab)->postJson($this->url('tabs.send', ['tab' => $tab->id]))
            ->assertCreated()
            ->assertJsonPath('ticket.number', 1)
            ->assertJsonPath('ticket.label', 'T1')
            ->assertJsonPath('ticket.is_addition', false)
            ->assertJsonCount(2, 'ticket.items');

        // Nothing new: nothing to send.
        $this->onTab($tab)->postJson($this->url('tabs.send', ['tab' => $tab->id]))->assertStatus(422);

        // One more rice: the next ticket is an addition with just that.
        $this->add($tab, $this->rice, 1);
        $this->onTab($tab)->postJson($this->url('tabs.send', ['tab' => $tab->id]))
            ->assertCreated()
            ->assertJsonPath('ticket.number', 2)
            ->assertJsonPath('ticket.is_addition', true)
            ->assertJsonCount(1, 'ticket.items')
            ->assertJsonPath('ticket.items.0.name', 'Rice')
            ->assertJsonPath('ticket.items.0.quantity', 1);

        $this->onTab($tab)->getJson($this->url('tabs.tickets', ['tab' => $tab->id]))->assertJsonCount(2, 'tickets');
    }

    public function test_move_merge_and_split(): void
    {
        $one = $this->open(['dining_table_id' => $this->t1->id, 'guests' => 2]);
        $two = $this->open(['tab_name' => 'Ana', 'guests' => 1]);
        $this->add($one, $this->adobo, 1);
        $this->add($two, $this->rice, 3);
        $this->onTab($two)->postJson($this->url('tabs.send', ['tab' => $two->id]))->assertCreated();

        // Ana sits down at T2.
        $this->onTab($two)->postJson($this->url('tabs.move', ['tab' => $two->id]), ['dining_table_id' => $this->t2->id])
            ->assertOk()->assertJsonPath('tab.label', 'T2 · Ana');

        // The two tables join: Ana's lines and kitchen ticket go onto T1, and her tab closes.
        $this->onTab($two)->postJson($this->url('tabs.merge', ['tab' => $two->id]), ['into_sale_id' => $one->id])
            ->assertOk()->assertJsonPath('tab.id', $one->id);
        $this->assertNull(Sale::query()->find($two->id));
        $one->refresh();
        $this->assertEquals(180, (float) $one->grand_total);
        $this->assertSame(3, (int) $one->guests);
        $this->assertSame(1, KitchenTicket::query()->where('sale_id', $one->id)->count());

        // Separate checks: 2 of the 3 rice go to a new tab on the same table.
        $rice = $one->saleItems()->where('product_id', $this->rice->id)->sole();
        $newId = $this->onTab($one)->postJson($this->url('tabs.split', ['tab' => $one->id]), [
            'lines' => [['sale_item_id' => $rice->id, 'quantity' => 2]],
            'tab_name' => 'Ana',
        ])->assertCreated()->json('tab.id');

        $new = Sale::query()->findOrFail($newId);
        $this->assertSame($this->t1->id, (int) $new->dining_table_id);
        $this->assertEquals(40, (float) $new->grand_total);
        $this->assertEquals(140, (float) $one->fresh()->grand_total);
        // What the kitchen had stays counted on both halves: nothing of it is sent again.
        $this->assertEquals(2, (float) $new->saleItems()->sole()->sent_quantity);
        $this->assertEquals(1, (float) $rice->fresh()->sent_quantity);

        // Splitting off everything is refused, and so is a part of a piece.
        $adobo = $one->saleItems()->where('product_id', $this->adobo->id)->sole();
        $this->onTab($one)->postJson($this->url('tabs.split', ['tab' => $one->id]), [
            'lines' => [['sale_item_id' => $adobo->id, 'quantity' => 1], ['sale_item_id' => $rice->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->onTab($one)->postJson($this->url('tabs.split', ['tab' => $one->id]), [
            'lines' => [['sale_item_id' => $rice->id, 'quantity' => 0.5]],
        ])->assertStatus(422);
    }

    public function test_paying_one_tab_leaves_the_others_open_and_takes_its_stock(): void
    {
        $one = $this->open(['dining_table_id' => $this->t1->id]);
        $two = $this->open(['dining_table_id' => $this->t2->id]);
        $this->add($one, $this->adobo, 2);
        $this->add($two, $this->adobo, 1);

        $this->onTab($one)->postJson($this->url('sales.payment.store', ['sale' => $one->id]), ['payment_method' => 'cash'])->assertOk();

        $this->assertSame('paid', $one->fresh()->payment_status);
        $this->assertSame('pending', $two->fresh()->payment_status);
        $this->assertEquals(48, (float) ProductInventory::query()->where('product_id', $this->adobo->id)->value('quantity_on_hand'));

        // The paid tab is no longer the till's: the tables screen shows only T2's.
        $this->onTab($one)->getJson($this->url('tabs.index'))
            ->assertJsonCount(1, 'tabs')
            ->assertJsonPath('active_tab_id', null);
    }

    public function test_open_tabs_count_against_stock(): void
    {
        ProductInventory::query()->where('product_id', $this->adobo->id)->update(['quantity_on_hand' => 3, 'quantity_available' => 3]);
        $one = $this->open(['dining_table_id' => $this->t1->id]);
        $two = $this->open(['dining_table_id' => $this->t2->id]);

        $this->add($one, $this->adobo, 2)->assertOk();
        // Only 1 left once T1's 2 are counted.
        $this->add($two, $this->adobo, 2)->assertStatus(422)->assertJsonValidationErrors('stock');
        $this->add($two, $this->adobo, 1)->assertOk();
    }

    public function test_cancelling_an_empty_tab_just_closes_it_but_one_with_items_needs_a_managers_pin(): void
    {
        $empty = $this->open(['tab_name' => 'Walk-in']);
        $this->onTab($empty)->postJson($this->url('tabs.cancel', ['tab' => $empty->id]))->assertOk();
        $this->assertNull(Sale::query()->find($empty->id));

        $tab = $this->open(['dining_table_id' => $this->t1->id]);
        $this->add($tab, $this->adobo, 1);

        $this->onTab($tab)->postJson($this->url('tabs.cancel', ['tab' => $tab->id]))->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->onTab($tab)->postJson($this->url('tabs.cancel', ['tab' => $tab->id]), ['pin' => '0000'])->assertStatus(422);

        $this->onTab($tab)->postJson($this->url('tabs.cancel', ['tab' => $tab->id]), ['pin' => '2468', 'reason' => 'Guests left'])->assertOk();
        $tab->refresh();
        $this->assertSame('voided', $tab->payment_status);
        $this->assertSame($this->manager->id, (int) $tab->void_approved_by);
        $this->assertSame(1, VoidLog::query()->where('approver_id', $this->manager->id)->count());
    }

    public function test_another_organizations_tab_and_a_store_without_dine_in_are_refused(): void
    {
        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(6))]);
        $otherStore = InventoryLocation::query()->create([
            'domain' => $other->name_slug, 'name' => 'X', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true, 'dine_in' => true,
        ]);
        $theirs = Sale::query()->create([
            'domain' => $other->name_slug, 'location_id' => $otherStore->id, 'user_id' => $this->cashier->id,
            'payment_status' => 'pending', 'is_tab' => true, 'tab_name' => 'Theirs', 'invoice_number' => Str::random(10),
        ]);

        $this->actingAs($this->cashier)->postJson($this->url('tabs.select', ['tab' => $theirs->id]))->assertNotFound();
        $this->actingAs($this->cashier)->postJson($this->url('tabs.send', ['tab' => $theirs->id]))->assertNotFound();
        // Even remembered on the till, another organization's tab is not the cart.
        $this->onTab($theirs)->getJson($this->url('users.sales.current-pending', ['user' => $this->cashier->id]))
            ->assertJsonPath('sale', null);

        $this->store->update(['dine_in' => false]);
        $this->actingAs($this->cashier)->postJson($this->url('tabs.store'), ['tab_name' => 'Ana'])
            ->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_tables_setup_turns_dine_in_on_and_keeps_tables_with_open_tabs(): void
    {
        $this->store->update(['dine_in' => false]);
        $this->actingAs($this->cashier)->put($this->url('dining-tables.settings'), ['location_id' => $this->store->id, 'dine_in' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->store->fresh()->dine_in);

        $this->actingAs($this->cashier)->post($this->url('dining-tables.store'), [
            'location_id' => $this->store->id, 'name' => 'T3', 'area' => 'Patio', 'seats' => 6,
        ])->assertSessionHasNoErrors();
        $t3 = DiningTable::query()->where('name', 'T3')->sole();
        $this->assertNotSame([$this->t1->pos_x, $this->t1->pos_y], [$t3->pos_x, $t3->pos_y]);

        $this->actingAs($this->cashier)->post($this->url('dining-tables.store'), [
            'location_id' => $this->store->id, 'name' => 'T3', 'seats' => 2,
        ])->assertSessionHasErrors('name');

        $this->actingAs($this->cashier)->put($this->url('dining-tables.layout'), [
            'location_id' => $this->store->id, 'positions' => [['id' => $t3->id, 'pos_x' => 5, 'pos_y' => 3]],
        ])->assertSessionHasNoErrors();
        $this->assertSame([5, 3], [$t3->fresh()->pos_x, $t3->fresh()->pos_y]);

        $this->open(['dining_table_id' => $this->t1->id]);
        $this->actingAs($this->cashier)->delete($this->url('dining-tables.destroy', ['diningTable' => $this->t1->id]))
            ->assertSessionHasErrors('table');
        $this->actingAs($this->cashier)->put($this->url('dining-tables.settings'), ['location_id' => $this->store->id, 'dine_in' => false])
            ->assertSessionHasErrors('dine_in');
    }
}
