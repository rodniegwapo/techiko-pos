<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\CashOnHandMovement;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\RegisterShift;
use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Services\Finance\FinancialReportService;
use App\Support\Wallet\CashControlSnapshot;
use App\Support\Wallet\CashOnHand;
use Database\Seeders\CashOnHandPermissionSeeder;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cash on hand: the business's cash the owner holds. Collecting an approved shift, the end-of-day
 * cash-out and moving it back to a store only change where the money is; "Owner used it" is the
 * one line that takes money out of the business. Stores move money to each other only when both
 * have "Can transfer money" on.
 */
class CashOnHandTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private Product $product;

    private User $owner;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        $this->seed(ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create(['name' => 'Coh Org', 'name_slug' => 'coh-org-'.Str::lower(Str::random(6))]);
        $this->store = $this->store('Main');
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

        $this->owner = $this->user('admin', 2);
        $this->manager = $this->user('manager', 3);
        $this->cashier = $this->user('cashier', 5);
    }

    private function store(string $name, array $attributes = []): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $name === 'Main', ...$attributes,
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

    /** The cashier's shift: ₱200 opening, `quantity` × ₱100 cash sales, counted right, approved by the manager. */
    private function approvedShift(int $quantity = 5): RegisterShift
    {
        $this->actingAs($this->cashier)->post($this->url('register-shifts.start'), ['location_id' => $this->store->id, 'opening_cash' => 200])
            ->assertSessionHasNoErrors();
        $sale = Sale::query()->create([
            'user_id' => $this->cashier->id, 'location_id' => $this->store->id, 'domain' => $this->domain->name_slug,
            'payment_status' => 'pending', 'invoice_number' => Str::upper(Str::random(10)), 'transaction_date' => now(),
            'grand_total' => 0, 'total_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'payment_method' => 'cash',
        ]);
        $sale->saleItems()->create(['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 100, 'discount' => 0]);
        $sale->recalcTotals();
        $this->actingAs($this->cashier)->postJson($this->url('sales.payment.store', ['sale' => $sale->id]), ['payment_method' => 'cash'])->assertOk();

        $shift = RegisterShift::query()->where('user_id', $this->cashier->id)->latest('id')->firstOrFail();
        $this->actingAs($this->cashier)->post($this->url('register-shifts.end', ['registerShift' => $shift->id]), ['counted_cash' => 200 + $quantity * 100])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->url('register-shifts.approve', ['registerShift' => $shift->id]))->assertSessionHasNoErrors();

        return $shift->fresh();
    }

    private function collect(User $by, RegisterShift $shift, float $amount)
    {
        return $this->actingAs($by)->post($this->url('cash-on-hand.collect', ['registerShift' => $shift->id]), ['amount' => $amount]);
    }

    private function drawer(?InventoryLocation $store = null): float
    {
        return (float) CashControlSnapshot::build($this->domain, $store ?? $this->store, now()->toDateString(), (int) $this->owner->id)['expected_cash'];
    }

    /** Cash in drawers plus Cash on hand on the balance sheet. */
    private function businessCash(): float
    {
        $assets = collect(app(FinancialReportService::class)->balanceSheet($this->domain->name_slug)['assets'])->pluck('amount', 'key');

        return round((float) $assets['cash_in_drawer'] + (float) $assets['cash_on_hand'], 2);
    }

    private function ownerWithdrawals(): float
    {
        return (float) app(FinancialReportService::class)
            ->cashFlow($this->domain->name_slug, null, now()->startOfDay(), now()->endOfDay())['out']['owner_withdrawals'];
    }

    public function test_collecting_an_approved_shift_moves_its_cash_to_cash_on_hand_without_changing_the_business_cash(): void
    {
        $shift = $this->approvedShift(5);
        $this->assertEquals(700, $this->drawer());
        $cashBefore = $this->businessCash();
        $withdrawalsBefore = $this->ownerWithdrawals();

        // Leaves ₱200 change in the drawer.
        $this->collect($this->owner, $shift, 500)->assertSessionHasNoErrors();

        $this->assertEquals(200, $this->drawer());
        $this->assertEquals(500, CashOnHand::balance($this->domain->name_slug));
        $this->assertEquals($cashBefore, $this->businessCash(), 'only where the money is changed');
        $this->assertEquals($withdrawalsBefore, $this->ownerWithdrawals(), 'collecting is not an owner withdrawal');

        // The cashier's own count is told apart from the store's (Drawer history).
        $this->assertSame(WalletCashCountSubmission::SHIFT, $shift->countSubmissions()->value('kind'));

        $shift->refresh();
        $this->assertEquals(500, $shift->collected_amount);
        $this->assertSame($this->owner->id, (int) $shift->collected_by);
        // The two sides share one transfer_group.
        $line = CashOnHandMovement::query()->where('register_shift_id', $shift->id)->firstOrFail();
        $this->assertSame(WalletCashMovement::KIND_TO_CASH_ON_HAND,
            WalletCashMovement::query()->where('transfer_group', $line->transfer_group)->value('kind'));
    }

    public function test_a_shift_is_collected_once_by_an_owner_and_never_more_than_counted(): void
    {
        $this->actingAs($this->cashier)->post($this->url('register-shifts.start'), ['location_id' => $this->store->id, 'opening_cash' => 100]);
        $open = RegisterShift::query()->where('user_id', $this->cashier->id)->firstOrFail();
        $this->actingAs($this->cashier)->post($this->url('register-shifts.end', ['registerShift' => $open->id]), ['counted_cash' => 100]);
        // Ended but not approved yet.
        $this->collect($this->owner, $open, 100)->assertSessionHasErrors('shift');

        $shift = $this->approvedShift(3); // reuses the cashier's drawer: counted 500
        $this->collect($this->manager, $shift, 100)->assertSessionHasErrors('amount');
        $this->collect($this->owner, $shift, $shift->counted_cash + 1)->assertSessionHasErrors('amount');
        $this->collect($this->owner, $shift, 100)->assertSessionHasNoErrors();
        $this->collect($this->owner, $shift, 100)->assertSessionHasErrors('shift');

        $this->assertEquals(100, CashOnHand::balance($this->domain->name_slug));
    }

    public function test_the_end_of_day_cash_out_goes_into_cash_on_hand_and_that_day_cant_be_collected_again(): void
    {
        $shift = $this->approvedShift(5);
        $cashBefore = $this->businessCash();

        $this->actingAs($this->manager)->post($this->url('wallet-cash-ledger.close-shift'), [
            'location_id' => $this->store->id, 'business_date' => now()->toDateString(),
            'counted_cash' => 700, 'end_shift_action' => 'cashout_now',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(700, CashOnHand::balance($this->domain->name_slug));
        $this->assertSame(WalletCashMovement::KIND_TO_CASH_ON_HAND, WalletCashMovement::query()
            ->where('location_id', $this->store->id)->where('direction', 'out')->latest('id')->value('kind'));
        $this->assertEquals(0, $this->ownerWithdrawals(), 'the cash-out is not an owner withdrawal');
        $this->assertTrue(CashOnHand::dayCashedOut($this->domain->name_slug, (int) $this->store->id, now()->toDateString()));
        $this->assertTrue(CashOnHand::waitingToCollect($this->domain->name_slug)->isEmpty());

        // The day's cash already went in: collecting its shift would count it twice.
        $this->collect($this->owner, $shift, 100)->assertSessionHasErrors('shift');
        $this->assertGreaterThan(0, $cashBefore);
    }

    public function test_cash_on_hand_moves_to_a_store_and_owner_use_is_the_only_withdrawal(): void
    {
        $shift = $this->approvedShift(5);
        $this->collect($this->owner, $shift, 700)->assertSessionHasNoErrors();
        $branch = $this->store('Branch');
        $this->actingAs($this->owner)->post($this->url('register-shifts.start'), ['location_id' => $branch->id, 'opening_cash' => 0]);

        // Not more than there is.
        $this->actingAs($this->owner)->post($this->url('cash-on-hand.store'), ['action' => 'to_store', 'location_id' => $branch->id, 'amount' => 800])
            ->assertSessionHasErrors('amount');

        $cashBefore = $this->businessCash();
        $this->actingAs($this->owner)->post($this->url('cash-on-hand.store'), ['action' => 'to_store', 'location_id' => $branch->id, 'amount' => 300])
            ->assertSessionHasNoErrors();
        $this->assertEquals(400, CashOnHand::balance($this->domain->name_slug));
        $this->assertEquals(300, $this->drawer($branch));
        $this->assertEquals($cashBefore, $this->businessCash());
        // The owner's own shift at the branch isn't charged with it: it's the store's.
        $this->assertNull(WalletCashMovement::query()->where('kind', WalletCashMovement::KIND_FROM_CASH_ON_HAND)->value('register_shift_id'));

        $this->actingAs($this->owner)->post($this->url('cash-on-hand.store'), ['action' => 'owner_use', 'amount' => 150])
            ->assertSessionHasNoErrors();
        $this->assertEquals(250, CashOnHand::balance($this->domain->name_slug));
        $this->assertEquals(150, $this->ownerWithdrawals());
        $this->assertEquals(round($cashBefore - 150, 2), $this->businessCash());

        // A correction needs its reason; a manager can't move Cash on hand.
        $this->actingAs($this->owner)->post($this->url('cash-on-hand.store'), ['action' => 'adjustment', 'direction' => 'in', 'amount' => 50])
            ->assertSessionHasErrors('notes');
        $this->actingAs($this->manager)->post($this->url('cash-on-hand.store'), ['action' => 'owner_use', 'amount' => 10])
            ->assertSessionHasErrors('amount');
    }

    public function test_the_cash_on_hand_page_shows_the_balance_waiting_shifts_and_history(): void
    {
        $shift = $this->approvedShift(2);

        $this->actingAs($this->owner)->get($this->url('cash-on-hand.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Wallet/CashOnHand')
                ->where('balance', 0)
                ->has('waiting', 1)
                ->where('waiting.0.id', $shift->id)
                ->where('waiting.0.store', 'Main'));

        $this->collect($this->owner, $shift, 400)->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get($this->url('cash-on-hand.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balance', 400)
                ->has('waiting', 0)
                ->where('lines.data.0.kind', CashOnHandMovement::COLLECTED)
                ->where('lines.data.0.balance_after', 400));

        // Counted money shows who collected it, and no Collect button any more (the admin role has
        // Cash on hand's permissions once its seeder ran).
        $this->seed(CashOnHandPermissionSeeder::class);
        $this->actingAs($this->owner)->get($this->url('register-shifts.index', ['status' => 'approved', 'location_id' => $this->store->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canCollect', true)
                ->where('shifts.data.0.collected_by', $this->owner->name)
                ->where('shifts.data.0.collectable', false));
    }

    public function test_stores_transfer_money_only_when_both_have_the_switch_on(): void
    {
        $this->approvedShift(5);
        $branch = $this->store('Branch');
        $this->actingAs($this->owner)->post($this->url('register-shifts.start'), ['location_id' => $branch->id, 'opening_cash' => 0]);
        $transfer = fn () => $this->actingAs($this->owner)->post($this->url('wallet-cash-ledger.store'), [
            'location_id' => $this->store->id, 'kind' => 'transfer', 'amount' => 100,
            'movement_date' => now()->toDateString(), 'to_location_id' => $branch->id,
        ]);

        $transfer()->assertSessionHasErrors('to_location_id');
        $this->store->update(['allows_money_transfer' => true]);
        $transfer()->assertSessionHasErrors('to_location_id');

        $this->actingAs($this->owner)->get($this->url('wallet.cash-in-out', ['location_id' => $this->store->id]))
            ->assertInertia(fn (Assert $page) => $page->has('transferStores', 0));

        $branch->update(['allows_money_transfer' => true]);
        $this->actingAs($this->owner)->get($this->url('wallet.cash-in-out', ['location_id' => $this->store->id]))
            ->assertInertia(fn (Assert $page) => $page->has('transferStores', 1)->where('transferStores.0.name', 'Branch'));
        $transfer()->assertSessionHasNoErrors();
        $this->assertEquals(100, $this->drawer($branch));
    }

    public function test_only_an_owner_turns_the_switch_on_for_a_store(): void
    {
        $data = fn (bool $on) => [
            'name' => 'Main', 'code' => $this->store->code, 'type' => 'store', 'is_active' => true, 'allows_money_transfer' => $on,
        ];

        $this->actingAs($this->manager)->put($this->url('inventory.locations.update', ['location' => $this->store->id]), $data(true))
            ->assertSessionHasErrors('allows_money_transfer');
        $this->assertFalse($this->store->fresh()->allows_money_transfer);

        $this->actingAs($this->owner)->put($this->url('inventory.locations.update', ['location' => $this->store->id]), $data(true))
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->store->fresh()->allows_money_transfer);
    }
}
