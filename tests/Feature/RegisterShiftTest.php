<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Models\WalletShiftReopenLog;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
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

        // Counted money lists what still needs review.
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
}
