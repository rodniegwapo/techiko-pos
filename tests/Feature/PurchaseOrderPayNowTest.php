<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\WalletCashDailyExpected;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Receiving a purchase order and paying the supplier there and then, e.g. from a store's cash drawer. */
class PurchaseOrderPayNowTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $warehouse;

    private InventoryLocation $store;

    private Product $rice;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
        $this->seed(ProductSoldTypeSeeder::class);

        $this->domain = Domain::query()->create([
            'name' => 'Pay Org',
            'name_slug' => 'pay-org-'.Str::lower(Str::random(6)),
            'settings' => ['features' => ['finance' => true]],
        ]);
        $this->warehouse = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Warehouse', 'code' => Str::upper(Str::random(8)),
            'type' => 'warehouse', 'is_active' => true, 'is_default' => true,
        ]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Store A', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true,
        ]);
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => true]);
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $this->rice = Product::factory()->create([
            'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'name' => 'Rice',
            'track_inventory' => true, 'sold_type' => 'Piece', 'cost' => 10,
        ]);
    }

    private function route(string $name, array $params = []): string
    {
        return route($name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    /** 10 rice at ₱40, for the given location. */
    private function sendOrder(?InventoryLocation $for = null): PurchaseOrder
    {
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.store'), [
            'supplier_name' => 'Golden Grains',
            'location_id' => ($for ?? $this->store)->id,
            'send' => true,
            'lines' => [['product_id' => $this->rice->id, 'quantity_ordered' => 10, 'unit_cost' => 40]],
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::query()->forDomain($this->domain->name_slug)->with('lines')->latest('id')->first();
    }

    private function receive(PurchaseOrder $order, float $quantity, ?array $payNow, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(
            $this->route('domains.purchase-orders.receive', ['purchaseOrder' => $order->id]),
            array_filter([
                'lines' => [['id' => $order->lines->first()->id, 'quantity' => $quantity]],
                'pay_now' => $payNow,
            ]),
        );
    }

    private function openShift(bool $closed = false): WalletCashReconciliation
    {
        return WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug,
            'location_id' => $this->store->id,
            'business_date' => today()->toDateString(),
            'opening_cash' => 1000,
            'is_closed' => $closed,
        ]);
    }

    private function assertNothingReceived(PurchaseOrder $order): void
    {
        $this->assertSame('ordered', $order->fresh()->status);
        $this->assertEquals(0, (float) $order->lines()->first()->quantity_received);
        $this->assertFalse(InventoryMovement::query()->where('reference_type', 'PurchaseOrder')->exists());
        $this->assertSame(0, SupplierBill::query()->count());
        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_paying_part_from_a_store_drawer_takes_the_cash_out_of_that_drawer(): void
    {
        $recon = $this->openShift();
        $order = $this->sendOrder($this->warehouse);

        $this->receive($order, 10, ['amount' => 150, 'payment_method' => 'cash_register', 'location_id' => $this->store->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Delivery received and ₱150.00 paid to Golden Grains.');

        $bill = SupplierBill::query()->where('purchase_order_id', $order->id)->sole();
        $this->assertEquals(400, (float) $bill->amount);
        $this->assertSame('partial', $bill->status);
        $this->assertEquals(250, $bill->remaining);

        $payment = SupplierPayment::query()->sole();
        $this->assertSame($this->store->id, (int) $payment->location_id);
        $this->assertSame(today()->toDateString(), $payment->payment_date->toDateString());

        $line = WalletCashMovement::query()->findOrFail($payment->wallet_cash_movement_id);
        $this->assertSame('supplier_payment', $line->kind);
        $this->assertSame('out', $line->direction);
        $this->assertSame($this->store->id, (int) $line->location_id);
        $this->assertNull($line->payment_card_type_id);

        $expected = WalletCashDailyExpected::compute($this->domain->name_slug, $this->store->id, today()->toDateString(), $recon);
        $this->assertEquals(850, $expected['expected_cash']);
    }

    public function test_paying_more_than_the_bill_undoes_the_whole_delivery(): void
    {
        $this->openShift();
        $order = $this->sendOrder();

        $this->receive($order, 10, ['amount' => 401, 'payment_method' => 'cash_register', 'location_id' => $this->store->id])
            ->assertSessionHasErrors('pay_now.amount');

        $this->assertNothingReceived($order);
    }

    public function test_a_warehouse_has_no_cash_drawer(): void
    {
        $order = $this->sendOrder();

        $this->receive($order, 10, ['amount' => 100, 'payment_method' => 'cash_register', 'location_id' => $this->warehouse->id])
            ->assertSessionHasErrors('pay_now.location_id');

        $this->assertNothingReceived($order);
    }

    public function test_a_closed_shift_refuses_the_payment_and_the_delivery(): void
    {
        $this->openShift(closed: true);
        $order = $this->sendOrder();

        $this->receive($order, 10, ['amount' => 100, 'payment_method' => 'cash_register', 'location_id' => $this->store->id])
            ->assertSessionHasErrors('pay_now.payment_date');

        $this->assertNothingReceived($order);
    }

    public function test_by_bank_keeps_the_account_and_leaves_the_drawer_alone(): void
    {
        $account = FinancialAccount::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'BDO', 'type' => 'bank', 'is_active' => true,
        ]);
        $order = $this->sendOrder();

        $this->receive($order, 10, ['amount' => 400, 'payment_method' => 'bank', 'financial_account_id' => $account->id])
            ->assertSessionHasNoErrors();

        $payment = SupplierPayment::query()->sole();
        $this->assertSame($account->id, (int) $payment->financial_account_id);
        $this->assertSame('paid', SupplierBill::query()->sole()->status);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_someone_who_cannot_pay_bills_can_still_receive_but_not_pay(): void
    {
        $order = $this->sendOrder();
        $clerk = User::factory()->create(['domain' => $this->domain->name_slug]);

        $this->receive($order, 10, ['amount' => 100, 'payment_method' => 'bank'], $clerk)
            ->assertSessionHasErrors('pay_now');
        $this->assertNothingReceived($order);

        $this->receive($order, 10, null, $clerk)->assertSessionHasNoErrors();
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(0, SupplierPayment::query()->count());
    }

    public function test_without_finance_there_is_no_pay_now(): void
    {
        $this->domain->update(['settings' => ['features' => ['finance' => false]]]);
        $order = $this->sendOrder();

        $this->receive($order, 10, ['amount' => 100, 'payment_method' => 'bank'])->assertSessionHasErrors('pay_now');
        $this->assertNothingReceived($order);

        $this->actingAs($this->admin)->get($this->route('domains.purchase-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canPayNow', false)->where('drawerLocations', []));
    }

    public function test_an_order_remembers_which_drawer_will_pay_without_paying(): void
    {
        $this->actingAs($this->admin)->post($this->route('domains.purchase-orders.store'), [
            'supplier_name' => 'Golden Grains',
            'location_id' => $this->warehouse->id,
            'send' => true,
            'pay_method' => 'cash_register',
            'pay_location_id' => $this->store->id,
            'lines' => [['product_id' => $this->rice->id, 'quantity_ordered' => 10, 'unit_cost' => 40]],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->forDomain($this->domain->name_slug)->sole();
        $this->assertSame('cash_register', $order->pay_method);
        $this->assertSame($this->store->id, (int) $order->pay_location_id);
        $this->assertSame(0, WalletCashMovement::query()->count());
        $this->assertSame(0, SupplierPayment::query()->count());
    }

    public function test_pay_from_needs_a_store_drawer_and_the_right_to_pay(): void
    {
        $order = fn (array $more, ?User $as = null) => $this->actingAs($as ?? $this->admin)->post($this->route('domains.purchase-orders.store'), [
            'supplier_name' => 'Golden Grains',
            'location_id' => $this->store->id,
            'lines' => [['product_id' => $this->rice->id, 'quantity_ordered' => 1, 'unit_cost' => 40]],
            ...$more,
        ]);

        $order(['pay_method' => 'cash_register'])->assertSessionHasErrors('pay_location_id');
        $order(['pay_method' => 'cash_register', 'pay_location_id' => $this->warehouse->id])->assertSessionHasErrors('pay_location_id');
        $this->assertSame(0, PurchaseOrder::query()->count());

        // Someone who can't pay bills still orders; the choice is left for whoever pays.
        $clerk = User::factory()->create(['domain' => $this->domain->name_slug]);
        $order(['pay_method' => 'bank'], $clerk)->assertSessionHasNoErrors();
        $this->assertNull(PurchaseOrder::query()->sole()->pay_method);
    }

    public function test_the_page_offers_only_store_drawers(): void
    {
        $this->actingAs($this->admin)->get($this->route('domains.purchase-orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchasing/Index')
                ->where('canPayNow', true)
                ->has('drawerLocations', 1)
                ->where('drawerLocations.0.id', $this->store->id)
                ->has('paymentMethods'));
    }
}
