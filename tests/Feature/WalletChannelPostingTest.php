<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Services\ExpenseService;
use App\Services\Finance\SupplierPaymentService;
use App\Support\Wallet\ChannelBalances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Paid by bank or e-wallet from an account linked to a payment channel: the money comes off that
 * channel's balance, through one ledger line kept in step with the record. Two places in a store
 * (drawer, channels) or two stores can trade money with a transfer: a balanced out/in pair.
 */
class WalletChannelPostingTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $branch;

    private PaymentCardType $gcash;

    private PaymentCardType $branchBank;

    private ExpenseCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create([
            'name' => 'Channel Org',
            'name_slug' => 'channel-org-'.Str::lower(Str::random(8)),
        ]);
        $this->store = $this->location('Main', true);
        $this->branch = $this->location('Branch');
        $this->gcash = $this->channel($this->store, 'GCash', 'ewallet');
        $this->branchBank = $this->channel($this->branch, 'BDO', 'bank');
        ExpenseCategory::ensureDefaults($this->domain->name_slug);
        $this->rent = ExpenseCategory::query()->forDomain($this->domain->name_slug)->where('name', 'Rent')->firstOrFail();
    }

    private function location(string $name, bool $default = false): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $default,
        ]);
    }

    private function channel(InventoryLocation $at, string $name, string $kind): PaymentCardType
    {
        return PaymentCardType::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $at->id, 'name' => $name, 'kind' => $kind,
            'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function account(?PaymentCardType $channel, string $type = 'ewallet'): FinancialAccount
    {
        return FinancialAccount::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Account '.Str::random(4), 'type' => $type,
            'is_active' => true, 'payment_card_type_id' => $channel?->id,
        ]);
    }

    private function user(int $level = 3): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'role-'.$level, 'guard_name' => 'web']);
        $role->forceFill(['level' => $level])->save();
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function expense(array $overrides = []): Expense
    {
        return app(ExpenseService::class)->create(array_merge([
            'domain' => $this->domain->name_slug,
            'location_id' => $this->store->id,
            'expense_category_id' => $this->rent->id,
            'amount' => 1200,
            'expense_date' => today()->toDateString(),
            'description' => 'Rent',
            'payment_method' => 'ewallet',
            'user_id' => $this->user()->id,
        ], $overrides));
    }

    private function balance(PaymentCardType $channel): float
    {
        return ChannelBalances::forLocation($this->domain->name_slug, (int) $channel->location_id)
            ->firstWhere('id', $channel->id)['balance'];
    }

    public function test_an_expense_paid_from_a_linked_account_comes_off_the_channel(): void
    {
        $expense = $this->expense(['financial_account_id' => $this->account($this->gcash)->id]);

        $line = WalletCashMovement::query()->findOrFail($expense->wallet_cash_movement_id);
        $this->assertSame($this->gcash->id, $line->payment_card_type_id);
        $this->assertSame('out', $line->direction);
        $this->assertSame(-1200.0, $this->balance($this->gcash));
    }

    public function test_an_unlinked_account_leaves_the_wallet_alone(): void
    {
        $expense = $this->expense(['financial_account_id' => $this->account(null)->id]);

        $this->assertNull($expense->wallet_cash_movement_id);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_the_line_follows_the_expense_between_channel_and_drawer(): void
    {
        $expense = $this->expense(['financial_account_id' => $this->account($this->gcash)->id]);
        $service = app(ExpenseService::class);

        $service->update($expense, ['payment_method' => 'cash_register', 'financial_account_id' => null]);
        $line = WalletCashMovement::query()->findOrFail($expense->wallet_cash_movement_id);
        $this->assertNull($line->payment_card_type_id, 'now out of the drawer');
        $this->assertSame(0.0, $this->balance($this->gcash));

        $service->update($expense, ['payment_method' => 'other']);
        $this->assertNull($expense->wallet_cash_movement_id);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_the_line_goes_on_the_channel_store_even_when_the_expense_is_for_another(): void
    {
        $expense = $this->expense(['payment_method' => 'bank', 'financial_account_id' => $this->account($this->branchBank, 'bank')->id]);

        $line = WalletCashMovement::query()->findOrFail($expense->wallet_cash_movement_id);
        $this->assertSame($this->branch->id, $line->location_id);
        $this->assertSame(-1200.0, $this->balance($this->branchBank));
    }

    public function test_a_closed_day_at_the_channel_store_is_refused(): void
    {
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'business_date' => today()->toDateString(),
            'opening_cash' => 0, 'is_closed' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->expense(['financial_account_id' => $this->account($this->gcash)->id]);
    }

    public function test_a_supplier_paid_by_e_wallet_comes_off_the_channel(): void
    {
        $supplier = Supplier::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Coke dist.']);
        $user = $this->user();
        $bill = SupplierBill::query()->create([
            'domain' => $this->domain->name_slug, 'supplier_id' => $supplier->id, 'bill_type' => 'inventory', 'user_id' => $user->id,
            'bill_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'amount' => 900,
        ]);

        $payment = app(SupplierPaymentService::class)->record($bill, [
            'payment_date' => today()->toDateString(), 'amount' => 900, 'payment_method' => 'ewallet',
            'financial_account_id' => $this->account($this->gcash)->id,
        ], $user);

        $this->assertNotNull($payment->wallet_cash_movement_id);
        $this->assertSame(-900.0, $this->balance($this->gcash));
    }

    public function test_a_transfer_from_the_drawer_to_a_channel_is_a_balanced_pair(): void
    {
        $this->actingAs($this->user())->post($this->ledgerUrl(), [
            'kind' => 'transfer', 'location_id' => $this->store->id, 'amount' => 2500,
            'movement_date' => today()->toDateString(), 'to_channel_id' => $this->gcash->id, 'notes' => 'Deposit',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $lines = WalletCashMovement::query()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([null, $this->gcash->id], $lines->pluck('payment_card_type_id')->all());
        $this->assertSame(['out', 'in'], $lines->pluck('direction')->all());
        $this->assertSame(['transfer_out', 'transfer_in'], $lines->pluck('kind')->all());
        $this->assertSame(1, $lines->pluck('transfer_group')->unique()->count());
        $this->assertSame(2500.0, $this->balance($this->gcash));
    }

    public function test_a_transfer_to_the_same_place_is_refused(): void
    {
        $this->actingAs($this->user())->post($this->ledgerUrl(), [
            'kind' => 'transfer', 'location_id' => $this->store->id, 'amount' => 100,
            'movement_date' => today()->toDateString(), 'from_channel_id' => $this->gcash->id, 'to_channel_id' => $this->gcash->id,
        ])->assertSessionHasErrors('to_channel_id');

        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_only_owners_and_admins_transfer_to_another_store(): void
    {
        $payload = [
            'kind' => 'transfer', 'location_id' => $this->store->id, 'amount' => 300, 'movement_date' => today()->toDateString(),
            'from_channel_id' => $this->gcash->id, 'to_location_id' => $this->branch->id, 'to_channel_id' => $this->branchBank->id,
        ];

        $this->actingAs($this->user(3))->post($this->ledgerUrl(), $payload)->assertSessionHasErrors('to_location_id');
        $this->assertSame(0, WalletCashMovement::query()->count());

        $this->actingAs($this->user(2))->post($this->ledgerUrl(), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(-300.0, $this->balance($this->gcash));
        $this->assertSame(300.0, $this->balance($this->branchBank));
    }

    public function test_a_channel_of_another_store_cant_be_the_source(): void
    {
        $this->actingAs($this->user(2))->post($this->ledgerUrl(), [
            'kind' => 'transfer', 'location_id' => $this->store->id, 'amount' => 300,
            'movement_date' => today()->toDateString(), 'from_channel_id' => $this->branchBank->id,
        ])->assertSessionHasErrors('from_channel_id');
    }

    private function ledgerUrl(): string
    {
        return route('domains.wallet-cash-ledger.store', ['domain' => $this->domain->name_slug]);
    }
}
