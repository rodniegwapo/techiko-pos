<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\CashOnHandMovement;
use App\Models\Domain;
use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\User;
use App\Services\Finance\FinancialReportService;
use App\Support\Wallet\AllStoresMoney;
use App\Support\Wallet\CashOnHand;
use App\Support\Wallet\WarehouseFund;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A warehouse's cash fund: the owner moves cash from Cash on hand to a warehouse, the warehouse
 * pays suppliers and expenses from it, and the owner can move what's left back. It is still the
 * business's cash, so it counts in where the money is and in the cash position.
 */
class WarehouseCashFundTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $warehouse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);

        $this->domain = Domain::query()->create([
            'name' => 'Fund Org', 'name_slug' => 'fund-org-'.Str::lower(Str::random(6)),
            'settings' => ['features' => ['finance' => true]],
        ]);
        $this->store = $this->location('Main', 'store', ['is_default' => true]);
        $this->warehouse = $this->location('Depot', 'warehouse');
        $this->owner = $this->user('admin', 2);

        // ₱10,000 the owner already holds.
        $this->move(['action' => 'adjustment', 'direction' => 'in', 'amount' => 10000, 'notes' => 'Starting cash'])->assertSessionHasNoErrors();
    }

    private function location(string $name, string $type, array $attributes = [], ?Domain $domain = null): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => ($domain ?? $this->domain)->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => $type, 'is_active' => true, ...$attributes,
        ]);
    }

    private function user(string $role, int $level): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->forceFill(['level' => $level])->save();
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $user->assignRole($r);

        return $user;
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    private function move(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post($this->url('cash-on-hand.store'), $data);
    }

    private function fund(): float
    {
        return WarehouseFund::balance($this->domain->name_slug, (int) $this->warehouse->id);
    }

    private function cashOnHand(): float
    {
        return CashOnHand::balance($this->domain->name_slug);
    }

    public function test_the_owner_moves_cash_to_a_warehouses_cash_fund(): void
    {
        $this->move(['action' => 'to_store', 'location_id' => $this->warehouse->id, 'amount' => 5000])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Moved to Depot's cash fund.");

        $this->assertSame(5000.0, $this->cashOnHand());
        $this->assertSame(5000.0, $this->fund());
        $this->assertDatabaseHas('cash_on_hand_movements', [
            'kind' => CashOnHandMovement::TO_STORE, 'location_id' => $this->warehouse->id, 'direction' => 'out', 'notes' => "To Depot's cash fund",
        ]);
    }

    public function test_the_page_lists_warehouses_after_stores(): void
    {
        $this->actingAs($this->owner)->get($this->url('cash-on-hand.index'))->assertInertia(fn (Assert $page) => $page
            ->where('stores.0.name', 'Main')
            ->where('stores.0.is_warehouse', false)
            ->where('stores.1.name', 'Depot')
            ->where('stores.1.is_warehouse', true)
            ->where('stores.1.channels', [])
            ->where('warehouses.0.name', 'Depot'));
    }

    public function test_cash_goes_back_to_cash_on_hand_up_to_what_the_fund_holds(): void
    {
        $this->move(['action' => 'to_store', 'location_id' => $this->warehouse->id, 'amount' => 5000]);

        $this->move(['action' => 'from_warehouse', 'location_id' => $this->warehouse->id, 'amount' => 5001])
            ->assertSessionHasErrors(['amount' => "Depot's cash fund has ₱5,000.00."]);

        $this->move(['action' => 'from_warehouse', 'location_id' => $this->warehouse->id, 'amount' => 1200])
            ->assertSessionHasNoErrors();
        $this->assertSame(3800.0, $this->fund());
        $this->assertSame(6200.0, $this->cashOnHand());
        $this->assertDatabaseHas('cash_on_hand_movements', ['kind' => CashOnHandMovement::FROM_WAREHOUSE, 'direction' => 'in', 'amount' => 1200]);
    }

    public function test_back_to_cash_on_hand_is_only_from_a_warehouse(): void
    {
        $this->move(['action' => 'from_warehouse', 'location_id' => $this->store->id, 'amount' => 10])
            ->assertSessionHasErrors('location_id');
    }

    public function test_only_an_owner_or_admin_moves_it(): void
    {
        $manager = $this->user('manager', 3);

        $this->move(['action' => 'to_store', 'location_id' => $this->warehouse->id, 'amount' => 100], $manager)
            ->assertSessionHasErrors('amount');
        $this->assertSame(0.0, $this->fund());
    }

    public function test_another_organizations_warehouse_is_refused(): void
    {
        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(6))]);
        $theirs = $this->location('Their depot', 'warehouse', [], $other);

        $this->move(['action' => 'to_store', 'location_id' => $theirs->id, 'amount' => 100])->assertSessionHasErrors('location_id');
        $this->move(['action' => 'from_warehouse', 'location_id' => $theirs->id, 'amount' => 100])->assertSessionHasErrors('location_id');
        $this->assertSame(10000.0, $this->cashOnHand());
    }

    public function test_a_warehouse_has_no_payment_channels_to_move_into(): void
    {
        $channel = PaymentCardType::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->warehouse->id, 'name' => 'GCash', 'kind' => 'ewallet', 'is_active' => true,
        ]);

        $this->move(['action' => 'to_store', 'location_id' => $this->warehouse->id, 'payment_card_type_id' => $channel->id, 'amount' => 100])
            ->assertSessionHasErrors('payment_card_type_id');
        $this->assertSame(0.0, $this->fund());
    }

    public function test_where_the_money_is_and_the_cash_position_count_the_fund(): void
    {
        $this->move(['action' => 'to_store', 'location_id' => $this->warehouse->id, 'amount' => 4000]);

        $money = AllStoresMoney::build($this->domain, (int) $this->owner->id);
        $this->assertSame([['id' => $this->warehouse->id, 'name' => 'Depot', 'balance' => 4000.0]], $money['warehouses']);
        // Still the business's cash: moving it changes nothing in the total.
        $this->assertSame(10000.0, $money['total']);

        $assets = collect(app(FinancialReportService::class)->balanceSheet($this->domain->name_slug)['assets'])->pluck('amount', 'key');
        $this->assertSame(4000.0, (float) $assets['warehouse_funds']);
        $this->assertSame(6000.0, (float) $assets['cash_on_hand']);
    }

    public function test_an_expense_paid_in_cash_at_the_warehouse_comes_out_of_its_fund(): void
    {
        $this->move(['action' => 'to_store', 'location_id' => $this->warehouse->id, 'amount' => 3000]);
        $category = ExpenseCategory::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Delivery', 'is_active' => true]);

        $this->actingAs($this->owner)->post($this->url('expenses.store'), [
            'expense_category_id' => $category->id,
            'amount' => 450,
            'expense_date' => now()->toDateString(),
            'description' => 'Truck fuel',
            'payment_method' => 'cash_register',
            'location_id' => $this->warehouse->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2550.0, $this->fund());
    }

    public function test_the_warehouses_cash_page_shows_its_fund(): void
    {
        $this->move(['action' => 'to_store', 'location_id' => $this->warehouse->id, 'amount' => 2500, 'notes' => 'For deliveries']);

        $this->actingAs($this->owner)->get($this->url('wallet.drawer', ['location_id' => $this->warehouse->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Wallet/StoresCash')
                ->where('warehouseFund.name', 'Depot')
                ->where('warehouseFund.balance', 2500)
                ->where('warehouseFund.lines.0.kind', 'from_cash_on_hand')
                ->where('warehouseFund.lines.0.notes', 'From Cash on hand: For deliveries')
                ->where('warehouseFund.lines.0.balance_after', 2500));
    }
}
