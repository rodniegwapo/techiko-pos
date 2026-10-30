<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Permission;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Models\WalletShiftReopenLog;
use App\Support\Wallet\RegisterShiftService;
use App\Support\Wallet\WalletCashDailyExpected;
use Database\Seeders\E2EEndSetupShiftsSeeder;
use Database\Seeders\E2ESalesSeeder;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Each cashier has their own shift at a store: their sales and count are theirs, a count that is
 * off waits for a manager without stopping anyone, and nothing is overwritten without a trace.
 */
class RegisterShiftTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        $this->seed(ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create(['name' => 'Shift Org', 'name_slug' => 'shift-org-'.Str::lower(Str::random(6))]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $this->product = Product::factory()->create([
            'domain' => $this->domain->name_slug,
            'category_id' => Category::factory()->create(['domain' => $this->domain->name_slug])->id,
            'sold_type' => 'Piece', 'track_inventory' => true, 'price' => 100,
        ]);
        $this->product->activeLocations()->attach($this->store->id, ['is_active' => true]);
        ProductInventory::query()->create([
            'product_id' => $this->product->id, 'location_id' => $this->store->id,
            'quantity_on_hand' => 100, 'quantity_reserved' => 0, 'quantity_available' => 100,
        ]);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    private function user(string $role, int $level): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->forceFill(['level' => $level])->save();
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $user->assignRole($r);

        return $user;
    }

    private function start(User $user, float $opening = 0)
    {
        return $this->actingAs($user)->post($this->url('register-shifts.start'), ['location_id' => $this->store->id, 'opening_cash' => $opening]);
    }

    private function end(User $user, RegisterShift $shift, float $counted)
    {
        return $this->actingAs($user)->post($this->url('register-shifts.end', ['registerShift' => $shift->id]), ['counted_cash' => $counted]);
    }

    /** Rings up `quantity` × ₱100 for the user and pays it. */
    private function sell(User $user, int $quantity, array $payment = ['payment_method' => 'cash'])
    {
        $sale = Sale::query()->create([
            'user_id' => $user->id, 'location_id' => $this->store->id, 'domain' => $this->domain->name_slug,
            'payment_status' => 'pending', 'invoice_number' => Str::upper(Str::random(10)), 'transaction_date' => now(),
            'grand_total' => 0, 'total_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'payment_method' => 'cash',
        ]);
        $sale->saleItems()->create(['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 100, 'discount' => 0]);
        $sale->recalcTotals();

        $response = $this->actingAs($user)->postJson($this->url('sales.payment.store', ['sale' => $sale->id]), $payment);

        return [$sale->fresh(), $response];
    }

    private function shiftOf(User $user): RegisterShift
    {
        return RegisterShift::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_two_cashiers_each_have_their_own_shift_and_sales(): void
    {
        $ana = $this->user('cashier', 4);
        $ben = $this->user('cashier', 4);

        // Without a shift of their own, a cashier can't take payment.
        [, $refused] = $this->sell($ana, 1);
        $refused->assertStatus(422)->assertJsonValidationErrors('shift');

        $this->start($ana, 1000)->assertSessionHasNoErrors();
        $this->start($ben, 500)->assertSessionHasNoErrors();
        // One open shift at a time.
        $this->start($ana, 0)->assertSessionHasErrors('shift');

        [$anaSale] = $this->sell($ana, 2);
        [$benSale] = $this->sell($ben, 3);

        $this->assertSame($this->shiftOf($ana)->id, (int) $anaSale->register_shift_id);
        $this->assertSame($this->shiftOf($ben)->id, (int) $benSale->register_shift_id);

        // A cash line added during a shift counts toward that cashier's drawer only.
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'out', 'amount' => 40,
            'kind' => 'owner_draw', 'movement_date' => today()->toDateString(), 'user_id' => $ben->id,
        ]);

        $this->actingAs($ana)->get($this->url('wallet.drawer', ['location_id' => $this->store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('myShift.status', 'open')
                ->where('myShift.figures.opening_cash', 1000)
                ->where('myShift.figures.paid_cash_sales', 200)
                ->where('myShift.figures.expected_cash', 1200));

        $this->actingAs($ben)->get($this->url('wallet.drawer', ['location_id' => $this->store->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('myShift.figures.manual_out', 40)
                ->where('myShift.figures.expected_cash', 760));
    }

    public function test_a_count_that_is_off_goes_to_review_and_nobody_is_stopped(): void
    {
        $ana = $this->user('cashier', 4);
        $ben = $this->user('cashier', 4);
        $this->start($ana, 1000);
        $this->start($ben, 0);
        $this->sell($ana, 2);

        // ₱1,200 expected, ₱1,150 counted: short ₱50.
        $this->end($ana, $this->shiftOf($ana), 1150)->assertSessionHasNoErrors();
        $shift = $this->shiftOf($ana);
        $this->assertSame(RegisterShift::NEEDS_REVIEW, $shift->status);
        $this->assertEquals(-50, $shift->variance);
        // Nothing posted to the store's ledger for it, and no one approved it yet.
        $this->assertNull(WalletCashCountSubmission::query()->sole()->approved_by);

        // Ben keeps selling.
        [, $ok] = $this->sell($ben, 1);
        $ok->assertOk();

        // Ana may start a new shift while the old one waits, kept apart from it.
        $this->start($ana, 300)->assertSessionHasNoErrors();
        $this->assertSame(2, RegisterShift::query()->where('user_id', $ana->id)->count());
        [$later] = $this->sell($ana, 1);
        $this->assertNotSame($shift->id, (int) $later->register_shift_id);
    }

    public function test_the_store_can_make_a_cashier_wait_for_review(): void
    {
        $this->domain->update(['settings' => ['sales' => ['allow_new_shift_while_review' => false]]]);
        $ana = $this->user('cashier', 4);
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 90);

        $this->start($ana, 0)->assertSessionHasErrors('shift');

        $this->actingAs($ana)->get($this->url('sales.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('shiftStatus.can_start', false)
                ->whereNot('shiftStatus.review_pending_since', null));
    }

    public function test_reopening_needs_a_reason_and_keeps_every_count(): void
    {
        $ana = $this->user('cashier', 4);
        $other = $this->user('cashier', 4);
        $this->start($ana, 500);
        $shift = $this->shiftOf($ana);
        $this->end($ana, $shift, 480);

        $reopen = fn (User $by, array $body) => $this->actingAs($by)->post($this->url('register-shifts.reopen', ['registerShift' => $shift->id]), $body);

        $reopen($ana, [])->assertSessionHasErrors('reason');
        // Another cashier can't reopen Ana's shift.
        $reopen($other, ['reason' => 'Mine now'])->assertSessionHasErrors('shift');

        $reopen($ana, ['reason' => 'Found ₱20 under the tray'])->assertSessionHasNoErrors();
        $log = WalletShiftReopenLog::query()->sole();
        $this->assertSame($shift->id, (int) $log->register_shift_id);
        $this->assertSame('Found ₱20 under the tray', $log->reason);
        $this->assertSame(RegisterShift::NEEDS_REVIEW, $log->before['status']);
        $this->assertEquals(480, $log->before['counted_cash']);
        $this->assertSame(RegisterShift::OPEN, $shift->fresh()->status);

        // Ending it again adds a count; the first one stays.
        $this->end($ana, $shift, 500)->assertSessionHasNoErrors();
        $this->assertSame([480.0, 500.0], WalletCashCountSubmission::query()->orderBy('id')->pluck('counted_cash')->all());
        $this->assertSame(RegisterShift::CLOSED, $shift->fresh()->status);
    }

    public function test_each_persons_opening_is_cash_at_the_store(): void
    {
        $ana = $this->user('cashier', 4);
        $ben = $this->user('cashier', 4);
        $storeOpening = fn () => (float) WalletCashReconciliation::query()->where('location_id', $this->store->id)->sole()->opening_cash;

        // The first shift opens the store's day with its cash; Ben's own drawer adds his.
        $this->start($ana, 1000)->assertSessionHasNoErrors();
        $this->assertEquals(1000, $storeOpening());
        $this->start($ben, 500)->assertSessionHasNoErrors();
        $this->assertEquals(1500, $storeOpening());
        $this->assertDatabaseHas('wallet_cash_opening_audits', ['new_opening_cash' => 1500, 'reason' => 'Added '.$ben->name.'\'s opening (₱500.00)']);

        // Ana's next shift reuses her drawer, already counted in.
        $this->end($ana, $this->shiftOf($ana), 1000);
        $this->start($ana, 1000)->assertSessionHasNoErrors();
        $this->assertEquals(1500, $storeOpening());
        $this->assertSame(0, WalletCashMovement::query()->where('notes', 'like', '% at the start of %')->count());
    }

    public function test_cash_put_in_or_taken_out_between_shifts_is_a_line_on_the_store_day(): void
    {
        $ana = $this->user('cashier', 4);
        $storeExpected = fn () => WalletCashDailyExpected::compute($this->domain->name_slug, $this->store->id, today()->toDateString(),
            WalletCashReconciliation::query()->where('location_id', $this->store->id)->sole())['expected_cash'];

        $this->start($ana, 300);
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'out', 'amount' => 200,
            'kind' => 'owner_draw', 'movement_date' => today()->toDateString(), 'user_id' => $ana->id,
        ]);
        $this->end($ana, $this->shiftOf($ana), 100)->assertSessionHasNoErrors();
        $this->assertEquals(100, $storeExpected());

        // The next shift starts with what the drawer kept.
        $this->actingAs($ana)->get($this->url('wallet.drawer', ['location_id' => $this->store->id]))
            ->assertInertia(fn (Assert $page) => $page->where('nextShiftOpening', 100));

        // She put ₱200 back in: the store's day has it, and her shift isn't counting it twice.
        $this->start($ana, 300)->assertSessionHasNoErrors();
        $this->assertEquals(300, $storeExpected());
        $this->assertDatabaseHas('wallet_cash_movements', [
            'kind' => 'adjustment', 'direction' => 'in', 'amount' => 200, 'register_shift_id' => null,
            'notes' => 'Cash added at the start of '.$ana->name.'\'s shift',
        ]);
        $this->assertEquals(300, app(RegisterShiftService::class)->figures($this->shiftOf($ana))['expected_cash']);
        $this->actingAs($ana)->get($this->url('wallet.drawer', ['location_id' => $this->store->id]))
            ->assertInertia(fn (Assert $page) => $page->where('nextShiftOpening', null));

        // Starting with less takes the rest out.
        $this->end($ana, $this->shiftOf($ana), 300);
        $this->start($ana, 50)->assertSessionHasNoErrors();
        $this->assertEquals(50, $storeExpected());
        $this->assertDatabaseHas('wallet_cash_movements', ['kind' => 'adjustment', 'direction' => 'out', 'amount' => 250]);
    }

    public function test_cash_collected_from_a_shift_leaves_the_next_shift_drawer(): void
    {
        $ana = $this->user('cashier', 4);
        $this->start($ana, 300);
        $this->end($ana, $this->shiftOf($ana), 300);
        $this->shiftOf($ana)->update(['status' => RegisterShift::APPROVED, 'collected_amount' => 300, 'collected_at' => now()]);

        $this->assertEquals(0, app(RegisterShiftService::class)->carriedOpening($this->domain->name_slug, $this->store->id, $ana->id));
        $this->start($ana, 0)->assertSessionHasNoErrors();
        $this->assertSame(0, WalletCashMovement::query()->where('notes', 'like', '% at the start of %')->count());
    }

    public function test_a_shift_from_an_earlier_day_takes_no_new_money(): void
    {
        $ana = $this->user('cashier', 4);
        $this->start($ana, 100);
        $shift = $this->shiftOf($ana);
        // Left open last night.
        $shift->update(['business_date' => today()->subDay()->toDateString(), 'opened_at' => now()->subDay()]);

        [, $refused] = $this->sell($ana, 1);
        $refused->assertStatus(422)->assertJsonValidationErrors('shift');
        $this->start($ana, 0)->assertSessionHasErrors('shift');
        $line = WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'in', 'amount' => 25,
            'kind' => 'adjustment', 'movement_date' => today()->toDateString(), 'user_id' => $ana->id,
        ]);
        $this->assertNull($line->register_shift_id);

        // Once ended, it isn't reopened: today's sales would land in yesterday's drawer.
        $this->end($ana, $shift, 100)->assertSessionHasNoErrors();
        $this->actingAs($ana)->post($this->url('register-shifts.reopen', ['registerShift' => $shift->id]), ['reason' => 'Forgot a sale'])
            ->assertSessionHasErrors('shift');
        $this->assertSame(RegisterShift::CLOSED, $shift->fresh()->status);
    }

    public function test_a_manager_reopens_a_wrong_count_for_the_cashier_to_count_again(): void
    {
        $ana = $this->user('cashier', 4);
        $boss = $this->user('manager', 3);
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 90);
        $this->assertSame(RegisterShift::NEEDS_REVIEW, $this->shiftOf($ana)->status);

        // From Counted money: the manager sends it back, with a reason.
        $this->actingAs($boss)->post($this->url('register-shifts.reopen', ['registerShift' => $this->shiftOf($ana)->id]), ['reason' => 'Count the coins again'])
            ->assertSessionHasNoErrors();

        $shift = $this->shiftOf($ana);
        $this->assertTrue($shift->isOpen());
        $this->assertNull($shift->counted_cash);
        $log = WalletShiftReopenLog::query()->where('register_shift_id', $shift->id)->sole();
        $this->assertSame($boss->id, (int) $log->reopened_by);
        $this->assertEquals(90, $log->before['counted_cash']);

        // Ana counts again; the new count replaces nothing, it's added.
        $this->end($ana, $shift, 100)->assertSessionHasNoErrors();
        $this->assertSame(2, WalletCashCountSubmission::query()->where('register_shift_id', $shift->id)->count());
    }

    public function test_a_manager_approves_counts_but_not_their_own(): void
    {
        $ana = $this->user('cashier', 4);
        $boss = $this->user('manager', 3);
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 90);
        $this->start($boss, 0);
        $this->end($boss, $this->shiftOf($boss), 10);

        $approve = fn (User $by, RegisterShift $s) => $this->actingAs($by)->post($this->url('register-shifts.approve', ['registerShift' => $s->id]), ['note' => 'Recounted, ok']);

        $approve($boss, $this->shiftOf($boss))->assertSessionHasErrors('shift');
        $approve($boss, $this->shiftOf($ana))->assertSessionHasNoErrors();

        $shift = $this->shiftOf($ana);
        $this->assertSame(RegisterShift::APPROVED, $shift->status);
        $this->assertSame($boss->id, (int) $shift->reviewed_by);
        $this->assertSame('Recounted, ok', $shift->review_note);
        $submission = WalletCashCountSubmission::query()->where('register_shift_id', $shift->id)->sole();
        $this->assertSame($boss->id, (int) $submission->approved_by);
        $this->assertNotNull($submission->approved_at);

        // Approved is final: corrections go through Cash in & out.
        $this->actingAs($ana)->post($this->url('register-shifts.reopen', ['registerShift' => $shift->id]), ['reason' => 'again'])
            ->assertSessionHasErrors('shift');

        // Shifts & counts lists what still needs review (for those who may see cashier shifts).
        $this->grant($boss, ['register-shifts.index']);
        $this->actingAs($boss)->get($this->url('register-shifts.index', ['location_id' => $this->store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Wallet/Counts')
                ->where('status', 'needs_review')
                ->has('shifts.data', 1)
                ->where('shifts.data.0.user.id', $boss->id));
    }

    public function test_money_per_user_shows_each_persons_takings_by_where_they_went(): void
    {
        $gcash = PaymentCardType::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'name' => 'GCash', 'kind' => 'ewallet', 'is_active' => true,
        ]);
        $ana = $this->user('cashier', 4);
        $ben = $this->user('cashier', 4);
        $boss = $this->user('manager', 3);
        $this->start($ana, 1000);
        $this->start($ben, 0);
        $this->sell($ana, 2);
        $this->sell($ana, 1, ['payment_method' => 'e-wallet', 'payment_card_type_id' => $gcash->id, 'payment_reference' => 'GC-9']);
        $this->sell($ben, 4);

        $users = collect($this->actingAs($boss)->getJson($this->url('register-shifts.money-by-user', ['location_id' => $this->store->id]))
            ->assertOk()->json('users'))->keyBy('user.id');

        $places = collect($users[$ana->id]['places'])->pluck('amount', 'label');
        $this->assertEquals(200, $places['Cash drawer']);
        $this->assertEquals(100, $places['GCash']);
        $this->assertEquals(300, $users[$ana->id]['total']);
        $this->assertEquals(1200, $users[$ana->id]['shifts'][0]['expected_cash']);
        $this->assertEquals(400, $users[$ben->id]['total']);
    }

    /** @param  list<string>  $routes */
    private function grant(User $user, array $routes): User
    {
        foreach ($routes as $route) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(
                ['route_name' => $route, 'guard_name' => 'web'],
                ['name' => 'Test '.$route.' '.Str::uuid()],
            ));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    private function gcash(): PaymentCardType
    {
        return PaymentCardType::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'name' => 'GCash', 'kind' => 'ewallet', 'is_active' => true,
        ]);
    }

    private function boss(): User
    {
        return $this->grant($this->user('manager', 3), ['wallet-cash-ledger.index', 'register-shifts.money-by-user']);
    }

    /** @return array<string, mixed> */
    private function moneyByPlace(User $as, array $query = []): array
    {
        return $this->actingAs($as)->get($this->url('wallet.balances', $query))->assertOk()->viewData('page')['props']['moneyByPlace'];
    }

    /** @param  array<string, mixed>  $money */
    private function place(array $money, string $key): array
    {
        return collect($money['places'])->firstWhere('key', $key);
    }

    public function test_where_money_is_shows_each_place_and_who_it_came_from(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $boss = $this->boss();
        $gcash = $this->gcash();
        // GCash already held ₱1,000 from before today.
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'payment_card_type_id' => $gcash->id,
            'direction' => 'in', 'amount' => 1000, 'kind' => 'adjustment', 'movement_date' => now()->subDays(3)->toDateString(), 'user_id' => $boss->id,
        ]);

        // Ana: ₱100 opening, ₱200 cash and ₱100 GCash, still on shift. Ben: ₱50 opening, ₱200 GCash, ended.
        $this->start($ana, 100);
        $this->sell($ana, 2)[1]->assertSuccessful();
        $this->sell($ana, 1, ['payment_method' => 'e-wallet', 'payment_card_type_id' => $gcash->id, 'payment_reference' => 'GC-1'])[1]->assertSuccessful();
        $this->start($ben, 50);
        $this->sell($ben, 2, ['payment_method' => 'e-wallet', 'payment_card_type_id' => $gcash->id, 'payment_reference' => 'GC-2'])[1]->assertSuccessful();
        $this->end($ben, $this->shiftOf($ben), 50)->assertSessionHasNoErrors();
        // The manager puts ₱20 of change in with no shift of their own: the store's, no cashier's.
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'direction' => 'in', 'amount' => 20,
            'kind' => 'adjustment', 'movement_date' => now()->toDateString(), 'user_id' => $boss->id,
        ]);

        $money = $this->moneyByPlace($boss);
        $this->assertTrue($money['is_today']);

        // The drawer: each cashier's tray now, and the store's ₱20.
        $drawer = $this->place($money, 'drawer');
        $this->assertEquals(370, $drawer['balance']);
        $trays = collect($drawer['split'])->pluck('amount', 'user.id');
        $this->assertEquals(300, $trays[$ana->id]);
        $this->assertEquals(50, $trays[$ben->id]);
        $this->assertEquals(20, $drawer['rest']['amount']);

        // GCash: ₱1,300, of which Ana ₱100 and Ben ₱200 today; ₱1,000 from before.
        $channel = $this->place($money, 'channel:'.$gcash->id);
        $this->assertEquals(1300, $channel['balance']);
        $takings = collect($channel['split'])->pluck('amount', 'user.id');
        $this->assertEquals(100, $takings[$ana->id]);
        $this->assertEquals(200, $takings[$ben->id]);
        $this->assertEquals(1000, $channel['rest']['amount']);

        // By cashier: the same money per person, and the store's drawer cash on its own row.
        $rows = collect($money['cashiers'])->keyBy('user.id');
        $this->assertEquals(300, $rows[$ana->id]['cells']['drawer']);
        $this->assertEquals(100, $rows[$ana->id]['cells']['channel:'.$gcash->id]);
        $this->assertEquals(400, $rows[$ana->id]['total']);
        $this->assertSame(RegisterShift::OPEN, $rows[$ana->id]['status']);
        $this->assertSame(RegisterShift::CLOSED, $rows[$ben->id]['status']);
        $this->assertEquals(20, $money['store']['drawer']);

        // Another day: nobody took money then, so it's all from before or elsewhere.
        $yesterday = $this->moneyByPlace($boss, ['date' => now()->subDay()->toDateString()]);
        $this->assertFalse($yesterday['is_today']);
        $this->assertSame([], $this->place($yesterday, 'channel:'.$gcash->id)['split']);
        $this->assertEquals(1300, $this->place($yesterday, 'channel:'.$gcash->id)['rest']['amount']);
        // Never a day ahead.
        $this->actingAs($boss)->get($this->url('wallet.balances', ['date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('date');
    }

    public function test_a_cashiers_next_shift_is_one_drawer_not_two(): void
    {
        $ana = $this->user('cashier', 5);
        $boss = $this->boss();

        // Ended with ₱100, then the next shift starts with that same ₱100 still in her tray.
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 100);
        $this->start($ana, 100);

        $drawer = $this->place($this->moneyByPlace($boss), 'drawer');
        $this->assertCount(1, $drawer['split']);
        $this->assertEquals(100, $drawer['split'][0]['amount']);
        $this->assertEquals(100, $drawer['balance']);
        $this->assertEquals(0, $drawer['rest']['amount']);
    }

    public function test_only_those_who_see_money_by_user_get_the_split(): void
    {
        $ledgerOnly = $this->grant($this->user('manager', 3), ['wallet-cash-ledger.index']);

        $this->actingAs($ledgerOnly)->get($this->url('wallet.balances'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('moneyByPlace', null)->has('channelBalances'));
    }

    public function test_a_cashier_sees_only_their_own_shift_and_supervisors_see_the_store_drawer(): void
    {
        $ana = $this->user('cashier', 5);
        $sue = $this->user('supervisor', 4);
        $this->start($ana, 100);
        $this->sell($ana, 1)[1]->assertSuccessful();

        $this->actingAs($ana)->get($this->url('wallet.drawer'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canSeeStoreDrawer', false)
                ->missing('cashControl.expected_cash')
                ->missing('cashControl.opening_cash')
                ->where('myShift.figures.expected_cash', 200)
                // Their own takings for the cards: just their cash sale.
                ->where('myTakings', fn ($places) => collect($places)->pluck('amount', 'key')->map(fn ($v) => (float) $v)->all() === ['cash:' => 100.0]));

        // The store's whole drawer isn't a cashier's to count or open.
        $body = ['business_date' => now()->toDateString(), 'counted_cash' => 100, 'opening_cash' => 100];
        $this->actingAs($ana)->post($this->url('wallet-cash-ledger.counted-cash.store'), $body)->assertForbidden();
        $this->actingAs($ana)->post($this->url('wallet-cash-ledger.opening-cash.store'), $body)->assertForbidden();

        $this->actingAs($sue)->get($this->url('wallet.drawer'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canSeeStoreDrawer', true)
                ->where('cashControl.expected_cash', 200)
                // A supervisor's own drawer comes first too: no sales of theirs yet.
                ->where('myTakings', [])
                ->where('cashOnHand', null));
        $this->actingAs($sue)->post($this->url('wallet-cash-ledger.counted-cash.store'), $body)->assertSessionHasNoErrors();
    }

    public function test_an_owner_sees_the_cash_on_hand_they_hold_on_todays_drawer(): void
    {
        $owner = $this->grant($this->user('admin', 2), ['cash-on-hand.index']);

        $this->actingAs($owner)->get($this->url('wallet.drawer'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cashOnHand.balance', fn ($v) => (float) $v === 0.0)
                ->where('cashOnHand.collected_today', fn ($v) => (float) $v === 0.0));
    }

    public function test_a_cashier_spot_checks_their_own_tray_without_ending_the_shift(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $sue = $this->user('supervisor', 4);
        $boss = $this->user('manager', 3);
        $this->start($ana, 100);
        $this->sell($ana, 2)[1]->assertSuccessful();
        $shift = $this->shiftOf($ana);
        $spot = fn (User $by, float $counted) => $this->actingAs($by)
            ->post($this->url('register-shifts.spot-check', ['registerShift' => $shift->id]), ['counted_cash' => $counted, 'notes' => 'Mid-day']);

        // ₱100 opening + ₱200 cash: Expected ₱300; she counts ₱290.
        $spot($ana, 290)->assertSessionHasNoErrors();
        $check = WalletCashCountSubmission::query()->where('register_shift_id', $shift->id)->sole();
        $this->assertSame(WalletCashCountSubmission::SPOT_CHECK, $check->kind);
        $this->assertEquals(300, $check->expected_cash_snapshot);
        $this->assertEquals(-10, $check->variance_snapshot);
        // Still on shift, and the store's own count untouched.
        $this->assertTrue($shift->fresh()->isOpen());
        $this->assertNull(WalletCashReconciliation::query()->where('location_id', $this->store->id)->value('counted_at'));

        $this->actingAs($ana)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page
            ->where('canSpotCheck', fn ($v) => is_bool($v))
            ->where('myShift.last_spot_check.counted_cash', fn ($v) => (float) $v === 290.0));

        // Only ever your own drawer: not another cashier's, not even a supervisor's or an owner's.
        $spot($ben, 300)->assertSessionHasErrors('shift');
        $spot($sue, 300)->assertSessionHasErrors('shift');
        $this->assertSame(1, WalletCashCountSubmission::query()->where('register_shift_id', $shift->id)->count());

        // Ended, the count it ended with is what a manager approves, not a spot check.
        $this->end($ana, $shift, 300)->assertSessionHasNoErrors();
        $spot($ana, 300)->assertSessionHasErrors('shift');
        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $shift->id]), [])->assertSessionHasNoErrors();
        $approved = WalletCashCountSubmission::query()->where('register_shift_id', $shift->id)->whereNotNull('approved_at')->sole();
        $this->assertSame(WalletCashCountSubmission::SHIFT, $approved->kind);
    }

    public function test_an_owner_sees_what_was_collected_into_cash_on_hand_today(): void
    {
        $ana = $this->user('cashier', 5);
        $owner = $this->grant($this->user('admin', 2), ['cash-on-hand.index', 'cash-on-hand.collect']);
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 100);
        $this->actingAs($owner)->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ana)->id]), []);
        $this->actingAs($owner)->post($this->url('cash-on-hand.collect', ['registerShift' => $this->shiftOf($ana)->id]), ['amount' => 100])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page
            ->where('cashOnHand.collected_today', fn ($v) => (float) $v === 100.0)
            ->where('cashOnHand.balance', fn ($v) => (float) $v === 100.0));

        // Cash on hand's own page: what came in today, and nothing moved back to the stores yet.
        $this->actingAs($owner)->get($this->url('cash-on-hand.index'))->assertInertia(fn (Assert $page) => $page
            ->where('today.collected', fn ($v) => (float) $v === 100.0)
            ->where('today.moved_to_stores', fn ($v) => (float) $v === 0.0));
    }

    public function test_todays_drawer_lists_each_cashiers_shift_adding_up_to_the_store(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $boss = $this->grant($this->user('manager', 3), ['register-shifts.approve']);

        // Ana: ₱100 opening, ₱200 cash, on shift. Ben: ₱50 opening, counted ₱40 (short ₱10), waiting for review.
        $this->start($ana, 100);
        $this->sell($ana, 2)[1]->assertSuccessful();
        $this->start($ben, 50);
        $this->end($ben, $this->shiftOf($ben), 40)->assertSessionHasNoErrors();

        $props = $this->actingAs($boss)->get($this->url('wallet.drawer'))->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['canApprove']);
        $byShift = $props['drawerByShift'];
        $rows = collect($byShift['rows'])->keyBy('user.id');

        $this->assertEquals(100, $rows[$ana->id]['opening_cash']);
        $this->assertEquals(200, $rows[$ana->id]['paid_cash_sales']);
        $this->assertEquals(300, $rows[$ana->id]['expected_cash']);
        $this->assertEquals(300, $rows[$ana->id]['in_tray']);
        $this->assertSame(RegisterShift::NEEDS_REVIEW, $rows[$ben->id]['status']);
        $this->assertEquals(50, $rows[$ben->id]['expected_cash']);
        $this->assertEquals(40, $rows[$ben->id]['counted_cash']);
        $this->assertEquals(40, $rows[$ben->id]['in_tray']);

        // The trays and the store's rest add up to the drawer's Expected.
        $this->assertEquals($props['cashControl']['expected_cash'], $byShift['total']);
        $this->assertEquals($byShift['total'], round($rows->sum('in_tray') + $byShift['store_rest'], 2));

        // Approved right from Today's drawer.
        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $rows[$ben->id]['id']]), ['note' => 'Found it'])
            ->assertSessionHasNoErrors();
        $this->assertSame(RegisterShift::APPROVED, $this->shiftOf($ben)->status);

        // A cashier gets only their own shift.
        $this->actingAs($ana)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page->where('drawerByShift', null));
    }

    public function test_the_e2e_teardown_ends_only_the_shifts_its_setup_opened(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $open = fn (User $user, ?string $notes) => RegisterShift::query()->create([
            'domain' => E2ESalesSeeder::DOMAIN, 'location_id' => $this->store->id, 'user_id' => $user->id,
            'status' => RegisterShift::OPEN, 'business_date' => now()->toDateString(), 'opening_cash' => 50,
            'opened_at' => now(), 'notes' => $notes,
        ]);
        $tagged = $open($ana, E2ESalesSeeder::SETUP_SHIFT_NOTE);
        $theirs = $open($ben, null);

        $this->seed(E2EEndSetupShiftsSeeder::class);

        // Ended the normal way, counted at its Expected; someone's own shift stays open.
        $this->assertSame(RegisterShift::CLOSED, $tagged->fresh()->status);
        $this->assertEquals(50, $tagged->fresh()->counted_cash);
        $this->assertTrue($theirs->fresh()->isOpen());
    }

    public function test_todays_drawer_also_lists_shifts_from_an_earlier_day_still_open_or_ended_today(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $cy = $this->user('cashier', 5);
        $boss = $this->user('manager', 3);
        $earlier = fn (User $user, array $rest) => RegisterShift::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'user_id' => $user->id,
            'business_date' => now()->subDays(3)->toDateString(), 'opening_cash' => 100, 'opened_at' => now()->subDays(3), ...$rest,
        ]);
        // Ana forgot to end hers; Ben ended his today; Cy's ended back then.
        $forgotten = $earlier($ana, ['status' => RegisterShift::OPEN]);
        $endedToday = $earlier($ben, ['status' => RegisterShift::NEEDS_REVIEW, 'closed_at' => now(), 'counted_cash' => 90, 'expected_cash' => 100, 'variance' => -10]);
        $earlier($cy, ['status' => RegisterShift::CLOSED, 'closed_at' => now()->subDays(3), 'counted_cash' => 100, 'expected_cash' => 100, 'variance' => 0]);

        $byShift = $this->actingAs($boss)->get($this->url('wallet.drawer'))->assertOk()->viewData('page')['props']['drawerByShift'];
        $rows = collect($byShift['rows'])->keyBy('id');

        $this->assertEqualsCanonicalizing([$forgotten->id, $endedToday->id], $rows->keys()->all());
        $this->assertTrue($rows[$forgotten->id]['earlier_day']);
        $this->assertTrue($rows[$endedToday->id]['earlier_day']);
        // Their money is that day's: not in today's trays, and today's figures still add up.
        $this->assertNull($rows[$forgotten->id]['in_tray']);
        $this->assertEquals($byShift['total'], round(collect($byShift['rows'])->sum('in_tray') + $byShift['store_rest'], 2));

        // Looking back at that day lists all three as that day's own.
        $then = $this->actingAs($boss)->get($this->url('wallet.drawer', ['business_date' => now()->subDays(3)->toDateString()]))
            ->viewData('page')['props']['drawerByShift'];
        $this->assertCount(3, $then['rows']);
    }

    public function test_todays_drawer_summary_and_the_counts_waiting_for_review(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $sue = $this->grant($this->user('supervisor', 4), ['register-shifts.approve']);
        $gcash = PaymentCardType::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'name' => 'GCash', 'kind' => 'ewallet', 'is_active' => true,
        ]);

        // Ana: ₱100 opening, ₱200 cash and ₱100 GCash, on shift. Ben: ₱50 opening, counted ₱40.
        $this->start($ana, 100);
        $this->sell($ana, 2)[1]->assertSuccessful();
        $this->sell($ana, 1, ['payment_method' => 'e-wallet', 'payment_card_type_id' => $gcash->id, 'payment_reference' => 'GC-7'])[1]->assertSuccessful();
        $this->start($ben, 50);
        $this->end($ben, $this->shiftOf($ben), 40);

        $this->actingAs($sue)->get($this->url('wallet.drawer'))->assertOk()->assertInertia(fn (Assert $page) => $page
            // Whoever approves counts sees short or over here too.
            ->where('canSeeCountResult', true)
            ->where('drawerSummary.cash_sales', fn ($v) => (float) $v === 200.0)
            ->where('drawerSummary.digital', fn ($v) => (float) $v === 100.0)
            ->where('drawerSummary.discrepancy', fn ($v) => (float) $v === -10.0)
            ->where('drawerSummary.needs_review', 1)
            ->has('requiredActions', 1)
            ->where('requiredActions.0.user.id', $ben->id));

        // A cashier: their own shift only, nothing to review.
        $this->actingAs($ana)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page
            ->where('canSeeCountResult', false)
            ->where('drawerSummary', null)
            ->where('requiredActions', []));
    }

    /** The store's drawer Expected on a day (WalletCashDailyExpected, as Today's drawer shows it). */
    private function storeExpected(?string $date = null): float
    {
        $date ??= now()->toDateString();
        $day = WalletCashReconciliation::query()->forWalletContext($this->domain->name_slug, (int) $this->store->id)->whereDate('business_date', $date)->first();

        return (float) WalletCashDailyExpected::compute($this->domain->name_slug, (int) $this->store->id, $date, $day)['expected_cash'];
    }

    private function differenceLines(): Collection
    {
        return WalletCashMovement::query()->where('kind', 'adjustment')->where('notes', 'like', 'Count difference on %')->get();
    }

    public function test_approving_a_count_that_is_over_or_short_books_the_difference_into_the_store_drawer(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $boss = $this->user('manager', 3);
        // Ana: ₱100 expected, counted ₱150 (over ₱50). Ben: ₱100 expected, counted ₱80 (short ₱20).
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 150);
        $this->start($ben, 100);
        $this->end($ben, $this->shiftOf($ben), 80);
        $before = $this->storeExpected();

        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ana)->id]), [])->assertSessionHasNoErrors();
        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ben)->id]), [])->assertSessionHasNoErrors();

        $lines = $this->differenceLines()->keyBy('direction');
        $this->assertCount(2, $lines);
        $this->assertEquals(50, $lines['in']->amount);
        $this->assertEquals(20, $lines['out']->amount);
        $this->assertStringContainsString("shift #{$this->shiftOf($ana)->id}", $lines['in']->notes);
        // The store's drawer, not the shift: the shift's own Expected stays what it was.
        $this->assertNull($lines['in']->register_shift_id);
        $this->assertSame(now()->toDateString(), $lines['in']->movement_date->toDateString());
        $this->assertEquals(100, app(RegisterShiftService::class)->figures($this->shiftOf($ana))['expected_cash']);
        // So the drawer now holds what was counted: +50 − 20.
        $this->assertEquals(round($before + 30, 2), $this->storeExpected());
    }

    public function test_approving_a_balanced_count_books_nothing(): void
    {
        $ana = $this->user('cashier', 5);
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 100);

        $this->actingAs($this->user('manager', 3))->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ana)->id]), [])
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $this->differenceLines());
    }

    public function test_an_earlier_days_difference_goes_on_that_day_or_today_once_that_day_was_carried_forward(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $boss = $this->user('manager', 3);
        $then = now()->subDays(3)->toDateString();
        $this->travelTo(now()->subDays(3));
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 130);
        $this->travelBack();

        // That day is still open: the difference is that day's.
        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ana)->id]), [])->assertSessionHasNoErrors();
        $this->assertSame($then, $this->differenceLines()->sole()->movement_date->toDateString());

        // Ben's day was ended and carried into the next opening: his difference lands today.
        $other = now()->subDays(5)->toDateString();
        $this->travelTo(now()->subDays(5));
        $this->start($ben, 100);
        $this->end($ben, $this->shiftOf($ben), 90);
        WalletCashReconciliation::query()->updateOrCreate(
            ['domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'business_date' => $other],
            ['is_closed' => true, 'closed_at' => now()],
        );
        $this->travelBack();
        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ben)->id]), [])->assertSessionHasNoErrors();
        $this->assertSame(now()->toDateString(), $this->differenceLines()->firstWhere('direction', 'out')->movement_date->toDateString());
    }

    public function test_collecting_a_whole_count_that_was_over_leaves_the_drawer_at_its_other_cash(): void
    {
        $ana = $this->user('cashier', 5);
        $owner = $this->grant($this->user('admin', 2), ['cash-on-hand.collect']);
        // Expected ₱100, counted ₱23,234: approved, then collected in full (what happened at Makati).
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 23234);
        $this->actingAs($owner)->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ana)->id]), [])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post($this->url('cash-on-hand.collect', ['registerShift' => $this->shiftOf($ana)->id]), ['amount' => 23234])
            ->assertSessionHasNoErrors();

        // Opening 100 + difference 23,134 − collected 23,234: nothing left, and never below zero.
        $this->assertEquals(0, $this->storeExpected());
    }

    public function test_the_catch_up_command_books_earlier_approved_differences_once(): void
    {
        $ana = $this->user('cashier', 5);
        $boss = $this->user('manager', 3);
        $this->start($ana, 100);
        $this->end($ana, $this->shiftOf($ana), 140);
        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $this->shiftOf($ana)->id]), []);
        // As if approved before differences were booked.
        $this->differenceLines()->each->delete();

        $this->artisan('wallet:book-shift-differences', ['--dry-run' => true])->assertSuccessful();
        $this->assertCount(0, $this->differenceLines(), 'a dry run changes nothing');

        $this->artisan('wallet:book-shift-differences')->assertSuccessful();
        $this->assertEquals(40, $this->differenceLines()->sole()->amount);

        $this->artisan('wallet:book-shift-differences')->assertSuccessful();
        $this->assertCount(1, $this->differenceLines(), 'running it again books nothing more');
    }

    public function test_a_forgotten_shift_is_overdue_and_a_manager_ends_it_for_the_cashier(): void
    {
        $ana = $this->user('cashier', 5);
        $ben = $this->user('cashier', 5);
        $boss = $this->user('manager', 3);
        $this->travelTo(now()->subDays(2));
        $this->start($ana, 150);
        $this->travelBack();
        $shift = $this->shiftOf($ana);

        // The cashier: their shift is overdue, and Sales says so instead of "open".
        $this->actingAs($ana)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page->where('myShift.overdue', true));
        $this->actingAs($ana)->get(route('domains.sales.index', ['domain' => $this->domain->name_slug]))->assertInertia(fn (Assert $page) => $page
            ->where('shiftStatus.is_open', false)
            ->where('shiftStatus.overdue_since', $shift->business_date->toDateString()));

        // The manager: listed as forgotten, not as "still on shift" today; they may end it.
        $this->actingAs($boss)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page
            ->where('canEndForOthers', true)
            ->has('overdueShifts', 1)
            ->where('overdueShifts.0.id', $shift->id)
            ->where('overdueShifts.0.expected_cash', fn ($v) => (float) $v === 150.0)
            ->where('openShiftsAtStore', []));

        // Another cashier can't end it; the manager counts the tray and ends it.
        $this->end($ben, $shift, 150)->assertSessionHasErrors('shift');
        $this->end($boss, $shift, 150)->assertSessionHasNoErrors();
        $this->assertSame(RegisterShift::CLOSED, $shift->fresh()->status);
        $this->assertFalse($shift->fresh()->isOverdue());
    }
}
