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
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\OpensTodaysShift;
use Tests\TestCase;

/**
 * Coming soon (config/features.php): dine-in tables are off for every organization, VIP too, and
 * only retail can be picked as the business type. Nothing saved is lost: a store's dine-in, its
 * tables and an organization's saved type come back when the switches open.
 */
class ComingSoonFeaturesTest extends TestCase
{
    use OpensTodaysShift;
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openTodaysShiftForNewStores();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
        config(['features.dine_in' => false, 'features.business_types' => 'retail']);

        // A VIP café that picked Food and serves dine-in, from before the switches went off.
        $this->domain = Domain::query()->create([
            'name' => 'Kapihan',
            'name_slug' => 'kapihan-'.Str::lower(Str::random(6)),
            'vip_at' => now(),
            'settings' => ['sales' => ['business_type' => 'food']],
        ]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true, 'dine_in' => true,
        ]);
        DiningTable::query()->create(['domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'name' => 'T1', 'seats' => 4]);
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    public function test_only_open_business_types_can_be_picked(): void
    {
        $this->assertSame(['retail'], Domain::availableBusinessTypes());
        config(['features.business_types' => 'grocery, services']);
        $this->assertSame(['retail', 'grocery', 'services'], Domain::availableBusinessTypes(), 'retail is always open');
        config(['features.business_types' => 'all']);
        $this->assertSame(Domain::BUSINESS_TYPES, Domain::availableBusinessTypes());
    }

    public function test_a_saved_type_that_is_coming_soon_reads_as_retail_and_is_kept(): void
    {
        $this->assertSame('retail', $this->domain->businessType());
        $this->assertTrue($this->domain->hasChosenBusinessType(), 'no "what do you sell?" banner');

        $this->actingAs($this->admin)->get($this->url('settings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('businessType', 'retail')
                ->where('availableBusinessTypes', ['retail'])
                ->where('hasDineIn', false));

        // Saving the card as it shows (retail) keeps Food for when it opens.
        $this->actingAs($this->admin)->patch($this->url('settings.update'), ['business_type' => 'retail'])->assertSessionHasNoErrors();
        $this->assertSame('food', $this->domain->fresh()->settings['sales']['business_type']);
        $this->assertNull($this->domain->fresh()->businessTypeChange(), 'nothing changed, nothing recorded');

        config(['features.business_types' => 'all']);
        $this->assertSame('food', $this->domain->fresh()->businessType());
    }

    public function test_settings_and_signup_refuse_a_type_that_is_coming_soon(): void
    {
        Event::fake();

        $this->actingAs($this->admin)->patch($this->url('settings.update'), ['business_type' => 'grocery'])
            ->assertSessionHasErrors('business_type');

        auth()->logout();
        $this->post('/register', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'password', 'password_confirmation' => 'password',
            'organization' => 'Ana Milk Tea', 'business_type' => 'food',
        ])->assertSessionHasErrors('business_type');
    }

    public function test_dine_in_is_off_even_for_a_vip_store_that_serves_it(): void
    {
        $this->assertFalse($this->domain->hasFeature('dine_in'));

        $this->actingAs($this->admin)->get($this->url('sales.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('dineIn.enabled', false)
                ->where('domainFeatures.dine_in', false));

        $this->actingAs($this->admin)->get($this->url('dining-tables.index'))
            ->assertRedirect()
            ->assertSessionHas('notice', 'Dine-in tables are coming soon.');

        // The store's dine-in and its table are kept.
        $this->assertTrue($this->store->fresh()->dine_in);
        $this->assertSame(1, DiningTable::query()->where('location_id', $this->store->id)->count());
    }

    public function test_a_till_sale_at_a_dine_in_store_gets_no_order_type_or_kitchen_ticket(): void
    {
        $this->seed(ProductSoldTypeSeeder::class);
        $product = Product::factory()->create([
            'domain' => $this->domain->name_slug, 'category_id' => Category::factory()->create(['domain' => $this->domain->name_slug])->id,
            'sold_type' => 'Piece', 'track_inventory' => true, 'price' => 50, 'cost' => 25,
        ]);
        $product->activeLocations()->attach($this->store->id, ['is_active' => true]);
        ProductInventory::query()->create([
            'product_id' => $product->id, 'location_id' => $this->store->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'quantity_available' => 10,
        ]);

        $sale = $this->actingAs($this->admin)->postJson($this->url('users.sales.cart.add', ['user' => $this->admin->id]), [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertOk()->json('sale.id');
        $this->actingAs($this->admin)->postJson($this->url('sales.payment.store', ['sale' => $sale]), ['payment_method' => 'cash'])
            ->assertOk()->assertJsonPath('kitchen_ticket', null);

        $this->assertNull(Sale::query()->find($sale)->order_type);
        $this->assertSame(0, KitchenTicket::query()->where('sale_id', $sale)->count());
    }
}
