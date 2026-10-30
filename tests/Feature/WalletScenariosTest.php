<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\CashOnHandMovement;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserPin;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\CashierDrawers;
use App\Support\Wallet\DrawerByShift;
use App\Support\Wallet\RegisterShiftService;
use App\Support\Wallet\WalletCashDailyExpected;
use Carbon\Carbon;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Twenty everyday situations in a store's cash and shifts, each written as what a store owner
 * would expect to happen. A failing scenario is a finding to look into, not a test to bend.
 * Business is in Asia/Manila; the app's clock is UTC.
 */
class WalletScenariosTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $branch;

    private Product $product;

    private User $manager;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        $this->seed(ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create(['name' => 'Scenario Org', 'name_slug' => 'scenario-'.Str::lower(Str::random(6)), 'timezone' => 'Asia/Manila']);
        $this->store = $this->makeStore('Makati');
        $this->branch = $this->makeStore('SM Mall');
        $this->product = Product::factory()->create([
            'domain' => $this->domain->name_slug,
            'category_id' => Category::factory()->create(['domain' => $this->domain->name_slug])->id,
            'sold_type' => 'Piece', 'track_inventory' => true, 'price' => 100,
        ]);
        foreach ([$this->store, $this->branch] as $store) {
            $this->product->activeLocations()->attach($store->id, ['is_active' => true]);
            ProductInventory::query()->create([
                'product_id' => $this->product->id, 'location_id' => $store->id,
                'quantity_on_hand' => 1000, 'quantity_reserved' => 0, 'quantity_available' => 1000,
            ]);
        }
        $this->manager = $this->user('manager', 3);
        $this->owner = $this->grant($this->user('admin', 2), ['cash-on-hand.index', 'cash-on-hand.collect']);
    }

    private function makeStore(string $name): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $name === 'Makati', 'allows_money_transfer' => true,
        ]);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    private function user(string $role, int $level, ?InventoryLocation $store = null): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->forceFill(['level' => $level])->save();
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => ($store ?? $this->store)->id]);
        $user->assignRole($r);

        return $user;
    }

    /** @param  list<string>  $routes */
    private function grant(User $user, array $routes): User
    {
        foreach ($routes as $route) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => $route, 'guard_name' => 'web'], ['name' => 'Test '.$route.' '.Str::uuid()]));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    private function start(User $user, float $opening = 0, ?InventoryLocation $store = null)
    {
        return $this->actingAs($user)->post($this->url('register-shifts.start'), ['location_id' => ($store ?? $this->store)->id, 'opening_cash' => $opening]);
    }

    private function shiftOf(User $user): RegisterShift
    {
        return RegisterShift::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function end(User $by, RegisterShift $shift, float $counted)
    {
        return $this->actingAs($by)->post($this->url('register-shifts.end', ['registerShift' => $shift->id]), ['counted_cash' => $counted]);
    }

    private function approve(RegisterShift $shift, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->manager)->post($this->url('register-shifts.approve', ['registerShift' => $shift->id]), []);
    }

    /** Rings up `quantity` × ₱100 at the store and pays it; returns [sale, response]. */
    private function sell(User $user, int $quantity, array $payment = ['payment_method' => 'cash'], ?InventoryLocation $store = null): array
    {
        $sale = Sale::query()->create([
            'user_id' => $user->id, 'location_id' => ($store ?? $this->store)->id, 'domain' => $this->domain->name_slug,
            'payment_status' => 'pending', 'invoice_number' => Str::upper(Str::random(10)), 'transaction_date' => now(),
            'grand_total' => 0, 'total_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'payment_method' => 'cash',
        ]);
        $sale->saleItems()->create(['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 100, 'discount' => 0]);
        $sale->recalcTotals();

        return [$sale->fresh(), $this->actingAs($user)->postJson($this->url('sales.payment.store', ['sale' => $sale->id]), $payment)];
    }

    /** The store's drawer Expected on a day, as Today's drawer shows it. */
    private function expected(?string $date = null, ?InventoryLocation $store = null): float
    {
        $store ??= $this->store;
        $date ??= now()->toDateString();
        $day = WalletCashReconciliation::query()->forWalletContext($this->domain->name_slug, (int) $store->id)->whereDate('business_date', $date)->first();

        return (float) WalletCashDailyExpected::compute($this->domain->name_slug, (int) $store->id, $date, $day)['expected_cash'];
    }

    private function endDay(string $date, float $counted, string $action = 'save_as_opening_cash', ?InventoryLocation $store = null)
    {
        // A minute later, as in a real store: a sale in the very second the day ends counts toward
        // the next round (opening_basis_at is compared with >=). Noted as a minor edge.
        $this->travel(1)->minutes();

        return $this->actingAs($this->manager)->post($this->url('wallet-cash-ledger.close-shift'), [
            'business_date' => $date, 'counted_cash' => $counted, 'end_shift_action' => $action, 'location_id' => ($store ?? $this->store)->id,
        ]);
    }

    private function collect(RegisterShift $shift, float $amount)
    {
        return $this->actingAs($this->owner)->post($this->url('cash-on-hand.collect', ['registerShift' => $shift->id]), ['amount' => $amount]);
    }

    private function manilaToday(): string
    {
        return now('Asia/Manila')->toDateString();
    }

    // ------------------------------------------------------------------ 1–3: an ordinary day, and a forgotten shift

    public function test_01_a_normal_day_adds_up_from_opening_to_the_end_of_day_cash_out(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $this->start($ana, 500)->assertSessionHasNoErrors();
        $this->start($ben, 300)->assertSessionHasNoErrors();
        $this->sell($ana, 2)[1]->assertOk();
        $this->sell($ben, 1)[1]->assertOk();
        $this->end($ana, $this->shiftOf($ana), 700)->assertSessionHasNoErrors();
        $this->end($ben, $this->shiftOf($ben), 400)->assertSessionHasNoErrors();
        $this->assertSame(RegisterShift::CLOSED, $this->shiftOf($ana)->status);

        // ₱800 opening + ₱300 sales: the store's drawer holds ₱1,100, both trays added up.
        $this->assertEquals(1100, $this->expected());
        $trays = CashierDrawers::build($this->domain->name_slug, (int) $this->store->id, now()->toDateString(), 1100);
        $this->assertEquals(1100, collect($trays['drawers'])->sum('amount'));
        $this->assertEquals(0, $trays['rest']);

        $this->endDay(now()->toDateString(), 1100, 'cashout_now')->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->expected(), 'everything went to Cash on hand');
        $this->assertEquals(1100, (float) CashOnHandMovement::query()->sum('amount'));
    }

    public function test_02_a_forgotten_shift_stops_payment_the_next_day_and_shows_on_todays_drawer(): void
    {
        $ana = $this->user('cashier', 5);
        $this->travelTo(now()->subDay());
        $this->start($ana, 200)->assertSessionHasNoErrors();
        $this->travelBack();

        $this->sell($ana, 1)[1]->assertStatus(422)->assertJsonPath('errors.shift.0', fn ($m) => str_contains($m, 'still open'));
        $rows = collect(DrawerByShift::build($this->domain->name_slug, (int) $this->store->id, now()->toDateString(), $this->expected())['rows']);
        $this->assertTrue($rows->firstWhere('id', $this->shiftOf($ana)->id)['earlier_day'] ?? false, 'listed as from an earlier day');
    }

    public function test_03_a_forgotten_shift_from_yesterday_doesnt_stop_the_manager_ending_today(): void
    {
        $ana = $this->user('cashier', 5);
        $this->travelTo(now()->subDay());
        $this->start($ana, 200)->assertSessionHasNoErrors();
        $this->travelBack();

        // Today has no shifts of its own: ending it shouldn't wait for yesterday's.
        $this->endDay(now()->toDateString(), 0)->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ 4–5: the business day is Manila's

    public function test_04_a_shift_started_at_6am_manila_belongs_to_that_manila_day(): void
    {
        $ana = $this->user('cashier', 5);
        $this->travelTo(Carbon::parse('2026-10-29 06:00', 'Asia/Manila'));
        $this->start($ana, 100)->assertSessionHasNoErrors();

        $this->assertSame('2026-10-29', $this->shiftOf($ana)->business_date->toDateString());
    }

    public function test_05_a_cashier_who_opened_at_7_30am_manila_still_sells_at_10am(): void
    {
        $ana = $this->user('cashier', 5);
        $this->travelTo(Carbon::parse('2026-10-29 07:30', 'Asia/Manila'));
        $this->start($ana, 100)->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-10-29 10:00', 'Asia/Manila'));

        $this->sell($ana, 1)[1]->assertOk();
    }

    // ------------------------------------------------------------------ 6–9: counts, spot checks, recounts

    public function test_06_a_count_typed_far_too_high_is_approved_and_collected_without_the_drawer_going_negative(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 0)->assertSessionHasNoErrors();
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'in', 'amount' => 250,
            'kind' => 'cash_sale_topup', 'movement_date' => now()->toDateString(), 'user_id' => $ana->id,
        ]);
        $this->end($ana, $this->shiftOf($ana), 23234)->assertSessionHasNoErrors();
        $this->approve($this->shiftOf($ana), $this->owner)->assertSessionHasNoErrors();
        $this->collect($this->shiftOf($ana), 23234)->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(0, $this->expected());
    }

    public function test_07_a_short_count_approved_then_collected_leaves_the_drawer_at_zero(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 300)->assertSessionHasNoErrors();
        $this->end($ana, $this->shiftOf($ana), 280)->assertSessionHasNoErrors();
        $this->approve($this->shiftOf($ana))->assertSessionHasNoErrors();
        $this->collect($this->shiftOf($ana), 280)->assertSessionHasNoErrors();

        $this->assertEquals(0, $this->expected());
    }

    public function test_08_a_spot_check_changes_nothing_and_isnt_what_gets_approved(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 100)->assertSessionHasNoErrors();
        $shift = $this->shiftOf($ana);
        $this->actingAs($ana)->post($this->url('register-shifts.spot-check', ['registerShift' => $shift->id]), ['counted_cash' => 90])->assertSessionHasNoErrors();
        $this->assertTrue($shift->fresh()->isOpen());
        $this->assertEquals(100, $this->expected());

        $this->sell($ana, 1)[1]->assertOk();
        $this->end($ana, $shift, 200)->assertSessionHasNoErrors();
        $this->assertSame(RegisterShift::CLOSED, $shift->fresh()->status, 'the end count matched; the spot check is history');
        $this->approve($shift->fresh())->assertSessionHasNoErrors();
        $approved = WalletCashCountSubmission::query()->where('register_shift_id', $shift->id)->whereNotNull('approved_at')->sole();
        $this->assertSame(WalletCashCountSubmission::SHIFT, $approved->kind);
    }

    public function test_09_a_recount_keeps_both_counts_and_the_status_follows_the_last(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 100)->assertSessionHasNoErrors();
        $shift = $this->shiftOf($ana);
        $this->end($ana, $shift, 80)->assertSessionHasNoErrors();
        $this->assertSame(RegisterShift::NEEDS_REVIEW, $shift->fresh()->status);

        $this->actingAs($this->manager)->post($this->url('register-shifts.reopen', ['registerShift' => $shift->id]), ['reason' => 'Recount the coins'])->assertSessionHasNoErrors();
        $this->end($ana, $shift, 100)->assertSessionHasNoErrors();

        $this->assertSame(RegisterShift::CLOSED, $shift->fresh()->status);
        $this->assertSame(2, WalletCashCountSubmission::query()->where('register_shift_id', $shift->id)->count());
    }

    // ------------------------------------------------------------------ 10–11: next shift, two devices

    public function test_10_a_new_shift_while_the_last_waits_for_review_opens_with_the_same_tray(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 100)->assertSessionHasNoErrors();
        $this->end($ana, $this->shiftOf($ana), 90)->assertSessionHasNoErrors();

        $this->assertEquals(90, app(RegisterShiftService::class)->carriedOpening($this->domain->name_slug, (int) $this->store->id, (int) $ana->id));
        $this->start($ana, 90)->assertSessionHasNoErrors();
        $this->assertTrue($this->shiftOf($ana)->isOpen());
        // Still one tray of ₱90 in the store's drawer.
        $this->assertEquals(90, collect(CashierDrawers::build($this->domain->name_slug, (int) $this->store->id, now()->toDateString(), $this->expected())['drawers'])->sum('amount'));
    }

    public function test_11_starting_the_same_shift_on_a_second_device_is_refused(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 100)->assertSessionHasNoErrors();
        $this->start($ana, 100)->assertSessionHasErrors('shift');

        $this->assertSame(1, RegisterShift::query()->where('user_id', $ana->id)->count());
    }

    // ------------------------------------------------------------------ 12–14: sales outside a shift, voids

    public function test_12_an_offline_sale_synced_after_the_shift_ended_is_in_the_store_drawer_and_named(): void
    {
        $this->markTestIncomplete('Open finding: an offline sale synced after the shift ended belongs to no shift. Owner to decide.');

        $ana = $this->user('cashier', 5);
        $this->start($ana, 100)->assertSessionHasNoErrors();
        $this->end($ana, $this->shiftOf($ana), 100)->assertSessionHasNoErrors();

        $this->actingAs($ana)->postJson($this->url('sales.offline-sync'), ['sales' => [[
            'client_mutation_id' => (string) Str::uuid(),
            'payload' => [
                'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
                'payment_method' => 'cash', 'location_id' => $this->store->id, 'cashier_user_id' => $ana->id, 'recorded_at' => now()->toIso8601String(),
            ],
        ]]])->assertOk();

        // The ₱100 is in the drawer; it belongs to no tray, so the store's own row holds it.
        $this->assertEquals(200, $this->expected());
        $trays = CashierDrawers::build($this->domain->name_slug, (int) $this->store->id, now()->toDateString(), 200);
        $this->assertEquals(100, $trays['rest']);
        // Finding to confirm: whose cash is it? The cashier rang it up, but no shift will ever be counted with it.
        $this->assertNotNull(Sale::query()->where('payment_status', 'paid')->latest('id')->first()->register_shift_id, 'an offline sale should still be someone\'s');
    }

    public function test_13_a_back_dated_sale_goes_on_that_day_without_a_shift(): void
    {
        $sue = $this->user('supervisor', 4);
        $yesterday = now()->subDay()->toDateString();
        [$sale, $res] = $this->sell($sue, 2, ['payment_method' => 'cash', 'transaction_date' => $yesterday]);
        $res->assertOk();

        $this->assertNull($sale->fresh()->register_shift_id);
        $this->assertSame($yesterday, Carbon::parse($sale->fresh()->transaction_date)->toDateString());
        $this->assertEquals(200, $this->expected($yesterday));
        $this->assertEquals(0, $this->expected());
    }

    public function test_14_voiding_a_cash_sale_after_the_shift_was_approved_keeps_shift_and_drawer_in_step(): void
    {
        $this->markTestIncomplete('Open finding: a void after the count was approved lowers the store drawer but not the cashier\'s tray. Owner to decide.');

        $ana = $this->user('cashier', 5);
        UserPin::query()->create(['user_id' => $this->manager->id, 'pin_code' => Hash::make('1234')]);
        $this->start($ana, 100)->assertSessionHasNoErrors();
        [$sale] = $this->sell($ana, 1);
        $shift = $this->shiftOf($ana);
        $this->end($ana, $shift, 200)->assertSessionHasNoErrors();
        $this->approve($shift->fresh())->assertSessionHasNoErrors();

        // The ₱100 is handed back to the customer after the count.
        $this->actingAs($this->manager)->postJson($this->url('sales-history.void', ['sale' => $sale->id]), ['pin_code' => '1234', 'reason' => 'Refund'])->assertOk();

        // The drawer now holds ₱100 less; what the shift says its tray holds should agree.
        $tray = collect(CashierDrawers::build($this->domain->name_slug, (int) $this->store->id, now()->toDateString(), $this->expected())['drawers'])->sum('amount');
        $this->assertEquals($this->expected(), $tray, 'store drawer and the cashier\'s tray agree after the void');
    }

    // ------------------------------------------------------------------ 15–20: money moved by managers and owners

    public function test_15_a_managers_cash_expense_with_no_shift_open_is_the_stores_not_a_trays(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 500)->assertSessionHasNoErrors();
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'out', 'amount' => 50,
            'kind' => 'expense', 'movement_date' => now()->toDateString(), 'user_id' => $this->manager->id,
        ]);

        $this->assertEquals(450, $this->expected());
        $trays = CashierDrawers::build($this->domain->name_slug, (int) $this->store->id, now()->toDateString(), 450);
        $this->assertEquals(500, collect($trays['drawers'])->sum('amount'), 'Ana\'s tray is untouched');
        $this->assertEquals(-50, $trays['rest']);
    }

    public function test_16_collecting_part_of_a_shift_leaves_the_rest_in_the_tray_for_the_next_shift(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 300)->assertSessionHasNoErrors();
        $this->end($ana, $this->shiftOf($ana), 300)->assertSessionHasNoErrors();
        $this->approve($this->shiftOf($ana))->assertSessionHasNoErrors();
        $this->collect($this->shiftOf($ana), 250)->assertSessionHasNoErrors();

        $this->assertEquals(50, app(RegisterShiftService::class)->carriedOpening($this->domain->name_slug, (int) $this->store->id, (int) $ana->id));
        $this->assertEquals(50, $this->expected());
    }

    public function test_17_collecting_an_earlier_days_shift_takes_it_from_that_day(): void
    {
        $ana = $this->user('cashier', 5);
        $then = now()->subDays(2)->toDateString();
        $this->travelTo(now()->subDays(2));
        $this->start($ana, 400)->assertSessionHasNoErrors();
        $this->end($ana, $this->shiftOf($ana), 400)->assertSessionHasNoErrors();
        $this->travelBack();
        $this->approve($this->shiftOf($ana))->assertSessionHasNoErrors();
        $this->collect($this->shiftOf($ana), 400)->assertSessionHasNoErrors();

        $this->assertEquals(0, $this->expected($then));
        $this->assertEquals(0, $this->expected());
    }

    public function test_18_a_day_already_cashed_out_cant_be_collected_again(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 200)->assertSessionHasNoErrors();
        $this->end($ana, $this->shiftOf($ana), 200)->assertSessionHasNoErrors();
        $this->approve($this->shiftOf($ana))->assertSessionHasNoErrors();
        $this->endDay(now()->toDateString(), 200, 'cashout_now')->assertSessionHasNoErrors();

        $this->collect($this->shiftOf($ana), 200)->assertSessionHasErrors('shift');
        $this->assertEquals(200, (float) CashOnHandMovement::query()->sum('amount'), 'counted once');
    }

    public function test_19_reopening_the_day_and_ending_it_again_doesnt_count_the_cash_twice(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 100)->assertSessionHasNoErrors();
        $this->sell($ana, 1)[1]->assertOk();
        $this->end($ana, $this->shiftOf($ana), 200)->assertSessionHasNoErrors();
        $this->endDay(now()->toDateString(), 200, 'cashout_now')->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post($this->url('wallet-cash-ledger.reopen-shift'), [
            'business_date' => now()->toDateString(), 'reason' => 'One more sale', 'location_id' => $this->store->id,
        ])->assertSessionHasNoErrors();
        $this->start($ana, 0)->assertSessionHasNoErrors();
        $this->sell($ana, 1)[1]->assertOk();
        $this->end($ana, $this->shiftOf($ana), 100)->assertSessionHasNoErrors();
        $this->endDay(now()->toDateString(), 100, 'cashout_now')->assertSessionHasNoErrors();

        $this->assertEquals(300, (float) CashOnHandMovement::query()->sum('amount'), '₱200 then ₱100, not ₱200 twice');
        $this->assertEquals(0, $this->expected());
    }

    public function test_20_moving_cash_to_another_store_changes_both_drawers_and_no_tray(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5, $this->branch);
        $this->start($ana, 1000)->assertSessionHasNoErrors();
        $this->start($ben, 200, $this->branch)->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post($this->url('wallet-cash-ledger.store'), [
            'kind' => 'transfer', 'amount' => 300, 'movement_date' => now()->toDateString(),
            'location_id' => $this->store->id, 'to_location_id' => $this->branch->id, 'to_channel_id' => null, 'from_channel_id' => null,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(700, $this->expected());
        $this->assertEquals(500, $this->expected(null, $this->branch));
        $this->assertEquals(1000, collect(CashierDrawers::build($this->domain->name_slug, (int) $this->store->id, now()->toDateString(), 700)['drawers'])->sum('amount'));
    }
}
