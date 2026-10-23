<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLocationArea;
use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\WalletCashBridgeExpected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A warehouse sees the whole business but not the selling floor; a store everything but the
 * books (when there is a warehouse to keep them at).
 */
class LocationAreasTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $warehouse;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create([
            'name' => 'Areas Org', 'name_slug' => 'areas-org-'.Str::lower(Str::random(6)),
            'settings' => ['features' => ['finance' => true]],
        ]);
        $location = fn (string $name, string $type, bool $default) => InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => $type, 'is_active' => true, 'is_default' => $default,
        ]);
        $this->store = $location('Main Store', 'store', true);
        $this->warehouse = $location('Warehouse', 'warehouse', false);
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true]);
    }

    private function url(string $name, InventoryLocation $at): string
    {
        return route($name, ['domain' => $this->domain->name_slug, 'location_id' => $at->id]);
    }

    private function staff(string $role, int $level): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->forceFill(['level' => $level])->save();
        $user = User::factory()->create([
            'domain' => $this->domain->name_slug, 'is_super_user' => false,
            'location_id' => $this->store->id, 'role_level' => $level,
        ]);
        $user->assignRole($r);

        return $user;
    }

    public function test_store_staff_keep_expenses_but_find_finance_at_the_warehouse(): void
    {
        $manager = $this->staff('manager', 3);

        $this->actingAs($manager)->get($this->url('domains.finance.dashboard', $this->store))
            ->assertRedirect()
            ->assertSessionHas('notice', EnsureLocationArea::MESSAGES['finance']);
        $this->actingAs($manager)->get($this->url('domains.expenses.index', $this->store))->assertOk();
    }

    public function test_owners_and_admins_see_finance_at_any_store(): void
    {
        $owner = $this->staff('admin', 2);

        $this->actingAs($owner)->get($this->url('domains.finance.dashboard', $this->store))->assertOk();
        $this->actingAs($owner)->get($this->url('domains.dashboard', $this->store))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locationAreas.hidden', []));
        $this->actingAs($this->admin)->get($this->url('domains.finance.dashboard', $this->warehouse))->assertOk();
    }

    public function test_without_a_warehouse_a_store_keeps_finance(): void
    {
        $this->warehouse->update(['is_active' => false]);

        $this->actingAs($this->staff('manager', 3))->get($this->url('domains.finance.dashboard', $this->store))->assertOk();
    }

    public function test_a_warehouse_turns_away_the_selling_floor_pages_but_not_their_data(): void
    {
        $this->actingAs($this->admin)->get($this->url('domains.customers.index', $this->warehouse))
            ->assertRedirect()
            ->assertSessionHas('notice', EnsureLocationArea::MESSAGES['selling_floor']);

        // Other screens (credit, sales) still look customers up.
        $this->actingAs($this->admin)->getJson($this->url('domains.customers.index', $this->warehouse))->assertOk();
    }

    public function test_the_page_knows_what_its_location_hides(): void
    {
        $this->actingAs($this->admin)->get($this->url('domains.dashboard', $this->warehouse))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locationAreas.hidden', ['selling_floor', 'payment_channels', 'cash_drawer'])
                ->where('allLocations', true));

        $this->actingAs($this->staff('manager', 3))->get($this->url('domains.dashboard', $this->store))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locationAreas.hidden', ['finance'])
                ->where('allLocations', false));
    }

    public function test_the_warehouse_cash_page_lists_what_stores_closed(): void
    {
        $day = now()->subDay()->toDateString();
        $shift = WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'business_date' => $day,
            'opening_cash' => 0, 'counted_cash' => 0, 'is_closed' => true, 'closed_at' => now(), 'closed_by' => $this->admin->id,
        ]);
        WalletCashCountSubmission::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'reconciliation_id' => $shift->id,
            'business_date' => $day, 'counted_cash' => 1500, 'expected_cash_snapshot' => 1520, 'variance_snapshot' => -20,
            'counted_by' => $this->admin->id, 'counted_at' => now(),
        ]);
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'out', 'amount' => 1500,
            'kind' => 'owner_draw', 'notes' => WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT, 'movement_date' => $day,
            'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get($this->url('domains.wallet.drawer', $this->warehouse))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Wallet/StoresCash')
                ->where('storesCash.rows.0.store', 'Main Store')
                ->where('storesCash.rows.0.counted_cash', 1500)
                ->where('storesCash.rows.0.variance', -20)
                ->where('storesCash.rows.0.cash_out', 1500)
                ->where('storesCash.totals.shifts', 1));

        // The store itself gets its own drawer.
        $this->actingAs($this->admin)->get($this->url('domains.wallet.drawer', $this->store))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Wallet/Drawer'));

        // A warehouse has no drawer of its own to list or check back on.
        foreach (['domains.wallet.cash-in-out', 'domains.wallet.history', 'domains.wallet.balances'] as $route) {
            $this->actingAs($this->admin)->get($this->url($route, $this->warehouse))->assertRedirect();
        }
    }
}
