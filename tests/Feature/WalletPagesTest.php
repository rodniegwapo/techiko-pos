<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Support\RoutePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cash & wallet as four pages (Today's drawer, Cash in & out, Where money is, Drawer history),
 * opened with the permissions the old Money movement and Payment channels pages had.
 */
class WalletPagesTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([RoleBasedAccessControl::class]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->domain = Domain::query()->create(['name' => 'Wallet Org', 'name_slug' => 'wallet-org-'.Str::lower(Str::random(6))]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main Store', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions): User
    {
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        foreach ($permissions as $routeName) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(
                ['route_name' => $routeName, 'guard_name' => 'web'],
                ['name' => 'Test '.$routeName.' '.Str::uuid()],
            ));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    private function url(string $page, array $query = []): string
    {
        return route('domains.wallet.'.$page, ['domain' => $this->domain->name_slug, ...$query]);
    }

    public function test_todays_drawer_shows_how_expected_is_reached(): void
    {
        $user = $this->user(['wallet.money-movement']);
        $today = today()->toDateString();
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'business_date' => $today, 'opening_cash' => 500,
        ]);
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'in', 'amount' => 200,
            'kind' => 'cash_sale_topup', 'movement_date' => $today, 'user_id' => $user->id,
        ]);

        $this->actingAs($user)->get($this->url('drawer'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Wallet/Drawer')
                ->where('cashControl.opening_cash', 500)
                ->where('cashControl.manual_in', 200)
                ->where('cashControl.expected_cash', 700)
                ->where('activeLocation.id', $this->store->id));
    }

    public function test_each_page_opens_with_the_old_pages_permission(): void
    {
        $drawerOnly = $this->user(['wallet.money-movement']);
        $this->actingAs($drawerOnly)->get($this->url('drawer'))->assertOk();
        // Drawer history (past counts, short/over) is for those who keep the books.
        $this->actingAs($drawerOnly)->get($this->url('history'))->assertForbidden();
        $this->actingAs($drawerOnly)->get($this->url('cash-in-out'))->assertForbidden();
        $this->actingAs($drawerOnly)->get($this->url('balances'))->assertForbidden();

        $ledger = $this->user(['wallet-cash-ledger.index']);
        $this->actingAs($ledger)->get($this->url('cash-in-out'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Wallet/CashInOut')->has('ledger.movements')->has('channels'));
        $this->actingAs($ledger)->get($this->url('balances'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Wallet/Balances')->where('canSeeBalances', true)->where('drawerExpected', 0));
        $this->actingAs($ledger)->get($this->url('drawer'))->assertForbidden();
        $this->actingAs($ledger)->get($this->url('history'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Wallet/History')->has('cashControl.count_submission_history')->has('closedShifts.rows'));

        // Channels only: Where money is opens, without the balances.
        $channels = $this->user(['payment-card-types.index']);
        $this->actingAs($channels)->get($this->url('balances'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canSeeBalances', false)->where('drawerExpected', null)->where('channelBalances', []));

        $this->actingAs($this->user([]))->get($this->url('drawer'))->assertForbidden();
    }

    public function test_the_payment_channels_page_no_longer_carries_the_drawer(): void
    {
        $this->actingAs($this->user(['payment-card-types.index']))
            ->get(route('domains.payment-card-types.index', ['domain' => $this->domain->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Wallet/CardTerminals')->missing('cashControl')->missing('ledger'));
    }

    public function test_old_money_movement_links_land_on_the_right_page(): void
    {
        $user = $this->user(['wallet.money-movement']);
        $old = fn (array $q = []) => route('domains.wallet.money-movement', ['domain' => $this->domain->name_slug, ...$q]);
        $date = today()->subDay()->toDateString();

        $this->actingAs($user)->get($old(['business_date' => $date, 'location_id' => $this->store->id]))
            ->assertRedirect($this->url('drawer', ['business_date' => $date, 'location_id' => $this->store->id]));
        $this->actingAs($user)->get($old(['view' => 'all']))
            ->assertRedirect($this->url('balances', ['tab' => 'all']));
        $this->actingAs($user)->get($old(['kind' => 'owner_draw', 'page' => 2]))
            ->assertRedirect($this->url('cash-in-out', ['kind' => 'owner_draw', 'page' => 2]));
    }

    public function test_the_menu_knows_which_pages_open_with_another_pages_permission(): void
    {
        $this->actingAs($this->user(['wallet.money-movement']))->get($this->url('drawer'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('routePermissionAliases', fn ($aliases) => $aliases['wallet.drawer'] === ['wallet.money-movement']
                    && $aliases['wallet.balances'] === ['wallet-cash-ledger.index', 'payment-card-types.index']));
    }

    public function test_route_permission_follows_the_aliases(): void
    {
        $this->assertSame(['wallet-cash-ledger.index'], RoutePermission::permissionsFor('domains.wallet.history'));
        $this->assertSame(['products.index'], RoutePermission::permissionsFor('domains.products.index'));

        $money = $this->user(['payment-card-types.money']);
        $this->assertTrue(RoutePermission::allows($money, 'payment-card-types.details'));
        $this->assertFalse(RoutePermission::allows($money, 'payment-card-types.index'));

        $billing = $this->user(['billing.servicing.index']);
        $this->assertTrue(RoutePermission::allows($billing, 'billing.gcash.legacy'));

        $this->assertFalse(RoutePermission::allows(null, 'wallet.drawer'));
        $this->assertTrue(RoutePermission::allows(User::factory()->create(['is_super_user' => true]), 'wallet.drawer'));
    }
}
