<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSellableLocation;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\User;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A warehouse holds and moves stock but doesn't sell: its sales screens and cart API are closed,
 * and its users land on Inventory instead of the sales screen.
 */
class WarehouseModeTest extends TestCase
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** @return array{domain: Domain, store: InventoryLocation, warehouse: InventoryLocation} */
    private function seedOrg(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Warehouse Org',
            'name_slug' => 'wh-org-'.Str::lower(Str::random(8)),
        ]);

        $make = fn (string $name, string $type, bool $default) => InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => $name,
            'code' => 'WH-'.Str::upper(Str::random(6)),
            'type' => $type,
            'is_active' => true,
            'is_default' => $default,
        ]);

        return [
            'domain' => $domain,
            'store' => $make('Main Store', 'store', true),
            'warehouse' => $make('Distribution Center', 'warehouse', false),
        ];
    }

    /** A store-bound user (role level 3+) working at $location, optionally allowed into Inventory. */
    private function userAt(array $ctx, InventoryLocation $location, bool $canOpenInventory = true): User
    {
        $user = User::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'is_super_user' => false,
            'role_level' => 3,
            'location_id' => $location->id,
        ]);

        if ($canOpenInventory) {
            $user->givePermissionTo(Permission::query()->create([
                'name' => 'Test inventory.index '.Str::uuid(),
                'guard_name' => 'web',
                'route_name' => 'inventory.index',
            ]));
        }

        return $user;
    }

    private function route(string $name, array $ctx, array $params = []): string
    {
        return route($name, ['domain' => $ctx['domain']->name_slug, ...$params]);
    }

    public function test_the_sales_screen_at_a_warehouse_sends_the_user_to_inventory(): void
    {
        $ctx = $this->seedOrg();
        $user = $this->userAt($ctx, $ctx['warehouse']);

        $this->actingAs($user)
            ->get($this->route('domains.sales.index', $ctx))
            ->assertRedirect($this->route('domains.inventory.index', $ctx))
            ->assertSessionHas('notice', EnsureSellableLocation::MESSAGE);
    }

    public function test_the_cart_api_at_a_warehouse_is_refused(): void
    {
        $ctx = $this->seedOrg();
        $user = $this->userAt($ctx, $ctx['warehouse']);

        $this->actingAs($user)
            ->getJson($this->route('domains.sales.products', $ctx))
            ->assertForbidden()
            ->assertJsonPath('message', EnsureSellableLocation::MESSAGE);

        $this->actingAs($user)
            ->postJson($this->route('domains.users.sales.cart.add', $ctx, ['user' => $user->id]), ['product_id' => 1])
            ->assertForbidden();
    }

    public function test_sales_history_stays_open_at_a_warehouse(): void
    {
        $ctx = $this->seedOrg();
        $user = $this->userAt($ctx, $ctx['warehouse']);

        $this->actingAs($user)
            ->get($this->route('domains.sales-history.index', $ctx))
            ->assertOk();
    }

    public function test_a_store_still_sells(): void
    {
        $ctx = $this->seedOrg();
        $user = $this->userAt($ctx, $ctx['store']);

        $this->actingAs($user)->getJson($this->route('domains.sales.products', $ctx))->assertOk();
    }

    public function test_an_admin_who_picks_the_warehouse_is_sent_to_inventory(): void
    {
        $ctx = $this->seedOrg();
        $admin = User::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'is_super_user' => false,
            'role_level' => 2,
        ]);
        $admin->givePermissionTo(Permission::query()->create([
            'name' => 'Test inventory.index '.Str::uuid(),
            'guard_name' => 'web',
            'route_name' => 'inventory.index',
        ]));

        $this->actingAs($admin)
            ->get($this->route('domains.sales.index', $ctx).'?location_id='.$ctx['warehouse']->id)
            ->assertRedirect($this->route('domains.inventory.index', $ctx, ['location_id' => $ctx['warehouse']->id]));

        $this->actingAs($admin)
            ->get($this->route('domains.sales.index', $ctx).'?location_id='.$ctx['store']->id)
            ->assertOk();
    }

    public function test_offline_sync_refuses_a_sale_recorded_at_a_warehouse(): void
    {
        $ctx = $this->seedOrg();
        $user = $this->userAt($ctx, $ctx['store']);
        $this->seed(ProductSoldTypeSeeder::class);
        $product = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'sold_type' => 'Piece',
            'category_id' => Category::factory()->create(['domain' => $ctx['domain']->name_slug])->id,
        ]);

        $response = $this->actingAs($user)->postJson($this->route('domains.sales.offline-sync', $ctx), [
            'sales' => [[
                'client_mutation_id' => (string) Str::uuid(),
                'payload' => [
                    'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
                    'payment_method' => 'cash',
                    'location_id' => $ctx['warehouse']->id,
                    'cashier_user_id' => $user->id,
                ],
            ]],
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            EnsureSellableLocation::MESSAGE,
            json_encode($response->json()),
        );
    }

    public function test_login_lands_on_inventory_for_a_warehouse_user_and_on_sales_for_a_store_user(): void
    {
        $ctx = $this->seedOrg();
        $warehouseUser = $this->userAt($ctx, $ctx['warehouse']);
        $storeUser = $this->userAt($ctx, $ctx['store']);

        $this->post('/login', ['email' => $warehouseUser->email, 'password' => 'password'])
            ->assertRedirect($this->route('domains.inventory.index', $ctx));
        $this->post('/logout');

        $this->post('/login', ['email' => $storeUser->email, 'password' => 'password'])
            ->assertRedirect($this->route('domains.sales.index', $ctx));
    }

    public function test_a_warehouse_user_who_cannot_open_inventory_lands_on_their_profile(): void
    {
        $ctx = $this->seedOrg();
        $user = $this->userAt($ctx, $ctx['warehouse'], canOpenInventory: false);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('profile.edit', ['domain' => $ctx['domain']->name_slug]));
    }
}
