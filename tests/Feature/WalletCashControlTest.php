<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\WalletCashBridgeExpected;
use App\Support\Wallet\WalletLedgerViewData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Inertia;
use Tests\TestCase;

class WalletCashControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
    }

    /**
     * @return array{domain: Domain, location: InventoryLocation, location2: InventoryLocation, user: User}
     */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Org '.Str::random(4),
            'name_slug' => 'org-'.Str::lower(Str::random(6)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => 'M'.Str::upper(Str::random(4)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        $location2 = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Branch',
            'code' => 'B'.Str::upper(Str::random(4)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'location_id' => $location->id,
        ]);

        return compact('domain', 'location', 'location2', 'user');
    }

    public function test_set_opening_cash_creates_and_updates_for_location_date(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(10)->toDateString();
        $url = route('domains.wallet-cash-ledger.opening-cash.store', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 1000,
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 1000.00,
            'opening_source' => 'manual',
        ]);
        $this->assertDatabaseHas('wallet_cash_opening_audits', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'old_opening_cash' => null,
            'new_opening_cash' => 1000.00,
            'delta_amount' => 1000.00,
            'changed_by' => $ctx['user']->id,
        ]);
        $this->assertDatabaseHas('wallet_cash_movements', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'movement_date' => $date,
            'direction' => 'in',
            'kind' => 'adjustment',
            'notes' => 'AUTO_CC_OPENING',
            'amount' => 1000.00,
            'user_id' => $ctx['user']->id,
        ]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 1500,
            'reason' => 'Owner changed float after recount',
        ])->assertRedirect();

        $this->assertSame(1, WalletCashReconciliation::query()->count());
        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 1500.00,
            'opening_source' => 'manual',
        ]);
        $this->assertSame(2, WalletCashOpeningAudit::query()->count());
        $this->assertDatabaseHas('wallet_cash_opening_audits', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'old_opening_cash' => 1000.00,
            'new_opening_cash' => 1500.00,
            'delta_amount' => 500.00,
            'changed_by' => $ctx['user']->id,
            'reason' => 'Owner changed float after recount',
        ]);
        $this->assertSame(
            1,
            WalletCashMovement::query()->where('notes', 'AUTO_CC_OPENING')->count()
        );
        $this->assertDatabaseHas('wallet_cash_movements', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'movement_date' => $date,
            'notes' => 'AUTO_CC_OPENING',
            'amount' => 1500.00,
            'user_id' => $ctx['user']->id,
        ]);
    }

    public function test_submit_counted_cash_records_user_and_timestamp(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(9)->toDateString();
        $url = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 980,
            'notes' => 'Drawer count at close',
        ])->assertRedirect();

        $row = WalletCashReconciliation::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($ctx['domain']->name_slug, $row->domain);
        $this->assertSame($ctx['location']->id, $row->location_id);
        $this->assertSame($ctx['user']->id, $row->counted_by);
        $this->assertNotNull($row->counted_at);
        $this->assertSame(980.0, (float) $row->counted_cash);
        $this->assertDatabaseHas('wallet_cash_movements', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'movement_date' => $date,
            'notes' => 'AUTO_CC_COUNTED_VARIANCE',
            'direction' => 'in',
            'amount' => 980.00,
            'user_id' => $ctx['user']->id,
        ]);
        $this->assertSame(1, WalletCashCountSubmission::query()->count());
    }

    public function test_submit_counted_cash_zero_variance_creates_no_variance_ledger_entry(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(7)->toDateString();
        $url = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
        ]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 100,
        ])->assertRedirect();

        $this->assertSame(
            0,
            WalletCashMovement::query()->where('notes', 'AUTO_CC_COUNTED_VARIANCE')->count()
        );

        $indexUrl = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ]);

        $this->actingAs($ctx['user'])->get($indexUrl)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Wallet/Index')
                ->where('cashControl.counted_by', $ctx['user']->id)
                ->where('cashControl.counted_by_user.id', $ctx['user']->id)
                ->where('cashControl.counted_by_user.name', $ctx['user']->name)
                ->where('cashControl.count_submission_history.0.counted_cash', 100)
            );

        $this->assertSame(1, WalletCashCountSubmission::query()->count());
    }

    public function test_submit_counted_cash_replaces_auto_variance_entry_instead_of_duplicating(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(6)->toDateString();
        $url = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
        ]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 90,
        ])->assertRedirect();

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 80,
        ])->assertRedirect();

        $this->assertSame(
            1,
            WalletCashMovement::query()->where('notes', 'AUTO_CC_COUNTED_VARIANCE')->count()
        );
        $this->assertDatabaseHas('wallet_cash_movements', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'movement_date' => $date,
            'notes' => 'AUTO_CC_COUNTED_VARIANCE',
            'direction' => 'out',
            'amount' => 20.00,
        ]);
    }

    public function test_submit_counted_cash_appends_submission_history_rows(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(5)->toDateString();
        $url = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
        ]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 95,
        ])->assertRedirect();

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 90,
        ])->assertRedirect();

        $this->assertSame(2, WalletCashCountSubmission::query()->count());

        $indexUrl = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ]);

        $this->actingAs($ctx['user'])->get($indexUrl)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Wallet/Index')
                ->where('cashControl.count_submission_history.0.counted_cash', 90)
                ->where('cashControl.count_submission_history.1.counted_cash', 95)
            );
    }

    public function test_running_cash_balance_excludes_book_only_auto_opening_and_variance(): void
    {
        $ctx = $this->seedContext();
        $d = $ctx['domain'];
        $loc = $ctx['location'];
        $u = $ctx['user'];
        $date = now()->subDays(1)->toDateString();

        WalletCashMovement::query()->create([
            'domain' => $d->name_slug,
            'location_id' => $loc->id,
            'payment_card_type_id' => null,
            'direction' => 'in',
            'amount' => 9999.00,
            'kind' => 'adjustment',
            'notes' => 'AUTO_CC_OPENING',
            'movement_date' => $date,
            'user_id' => $u->id,
        ]);
        WalletCashMovement::query()->create([
            'domain' => $d->name_slug,
            'location_id' => $loc->id,
            'payment_card_type_id' => null,
            'direction' => 'in',
            'amount' => 777.00,
            'kind' => 'adjustment',
            'notes' => 'AUTO_CC_COUNTED_VARIANCE',
            'movement_date' => $date,
            'user_id' => $u->id,
        ]);
        WalletCashMovement::query()->create([
            'domain' => $d->name_slug,
            'location_id' => $loc->id,
            'payment_card_type_id' => null,
            'direction' => 'in',
            'amount' => 100.00,
            'kind' => 'adjustment',
            'notes' => null,
            'movement_date' => $date,
            'user_id' => $u->id,
        ]);
        WalletCashMovement::query()->create([
            'domain' => $d->name_slug,
            'location_id' => $loc->id,
            'payment_card_type_id' => null,
            'direction' => 'out',
            'amount' => 50.00,
            'kind' => 'owner_draw',
            'notes' => WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT,
            'movement_date' => $date,
            'user_id' => $u->id,
        ]);

        $balance = WalletLedgerViewData::runningCashBalance($d, $loc);

        $this->assertSame(50.0, $balance);
    }

    public function test_wallet_index_returns_cash_control_formula_values(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(8)->toDateString();

        $recon = WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 1000,
            'counted_cash' => 1210,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
            'notes' => 'Night count',
        ]);

        WalletCashOpeningAudit::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'reconciliation_id' => $recon->id,
            'old_opening_cash' => null,
            'new_opening_cash' => 1000,
            'delta_amount' => 1000,
            'changed_by' => $ctx['user']->id,
            'changed_at' => now(),
        ]);

        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-CASH-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 200,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 200,
            'transaction_date' => $date,
        ]);
        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-CARD-'.Str::random(6),
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 700,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 700,
            'transaction_date' => $date,
        ]);

        WalletCashMovement::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'direction' => 'in',
            'amount' => 50,
            'kind' => 'adjustment',
            'movement_date' => $date,
            'user_id' => $ctx['user']->id,
        ]);
        WalletCashMovement::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'direction' => 'out',
            'amount' => 20,
            'kind' => 'owner_draw',
            'movement_date' => $date,
            'user_id' => $ctx['user']->id,
        ]);

        $url = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ]);

        $this->actingAs($ctx['user'])->get($url)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Wallet/Index')
                ->where('cashControl.business_date', $date)
                ->where('cashControl.opening_cash', 1000)
                ->where('cashControl.paid_cash_sales', 200)
                ->where('cashControl.manual_in', 50)
                ->where('cashControl.manual_out', 20)
                ->where('cashControl.expected_cash', 1230)
                ->where('cashControl.counted_cash', 1210)
                ->where('cashControl.variance', -20)
                ->where('cashControl.status', 'counted')
                ->where('cashControl.counted_by', $ctx['user']->id)
                ->where('cashControl.counted_by_user.id', $ctx['user']->id)
                ->where('cashControl.counted_by_user.name', $ctx['user']->name)
                ->where('cashControl.opening_last_updated_by_user.id', $ctx['user']->id)
                ->where('cashControl.opening_last_updated_by_user.name', $ctx['user']->name)
                ->has('cashControl.opening_audit_history', 1)
            );
    }

    public function test_wallet_index_suggests_opening_from_previous_counted_cash_when_current_not_saved(): void
    {
        $ctx = $this->seedContext();
        $previousDate = now()->subDay()->toDateString();
        $selectedDate = now()->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $previousDate,
            'opening_cash' => 800,
            'counted_cash' => 900,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        $url = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $selectedDate,
        ]);

        $this->actingAs($ctx['user'])->get($url)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Wallet/Index')
                ->where('cashControl.business_date', $selectedDate)
                ->where('cashControl.opening_is_saved', false)
                ->where('cashControl.opening_suggestion', 900)
                ->where('cashControl.suggestion_source_date', $previousDate)
                ->where('cashControl.opening_cash', 900)
            );
    }

    public function test_end_shift_is_blocked_when_counted_cash_is_missing(): void
    {
        $ctx = $this->seedContext();
        $date = now()->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 300,
        ]);

        $url = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'end_shift_action' => 'cashout_now',
        ])->assertSessionHasErrors(['counted_cash']);
    }

    public function test_end_shift_closes_shift_with_cashout_action_and_reopen_is_same_user_only(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(2)->toDateString();
        /** @var User $otherUser */
        $otherUser = User::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
        ]);

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 200,
            'counted_cash' => 250,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        $endUrl = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);
        $reopenUrl = route('domains.wallet-cash-ledger.reopen-shift', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($endUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'end_shift_action' => 'cashout_now',
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'is_closed' => 1,
            'closed_by' => $ctx['user']->id,
            'counted_cash' => 0.00,
            'counted_by' => null,
            'notes' => null,
        ]);
        $this->assertDatabaseHas('wallet_cash_movements', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'direction' => 'out',
            'kind' => 'owner_draw',
            'amount' => 250.00,
            'movement_date' => $date,
            'notes' => 'AUTO_CC_ENDSHIFT_CASHOUT',
        ]);
        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 0,
        ]);

        $reconRow = WalletCashReconciliation::query()
            ->where('domain', $ctx['domain']->name_slug)
            ->where('location_id', $ctx['location']->id)
            ->whereDate('business_date', $date)
            ->first();
        $this->assertNotNull($reconRow);
        $this->assertNull($reconRow->counted_at);

        $walletUrl = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ]);
        $this->actingAs($ctx['user'])->get($walletUrl)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->where('cashControl.opening_cash', 0)
                ->where('cashControl.expected_cash', 0)
                ->where('cashControl.manual_out', 0)
                ->where('cashControl.counted_at', null)
            );

        $this->actingAs($otherUser)->post($reopenUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ])->assertForbidden();

        $this->actingAs($ctx['user'])->post($reopenUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'is_closed' => 0,
        ]);
    }

    public function test_end_shift_save_as_opening_cash_updates_opening_and_audits(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(3)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 120,
            'counted_cash' => 180,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        $endUrl = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($endUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'end_shift_action' => 'save_as_opening_cash',
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 180.00,
            'counted_cash' => 0.00,
            'counted_by' => null,
            'counted_at' => null,
            'notes' => null,
            'is_closed' => 1,
        ]);
        $this->assertNotNull(
            WalletCashReconciliation::query()
                ->where('domain', $ctx['domain']->name_slug)
                ->where('location_id', $ctx['location']->id)
                ->whereDate('business_date', $date)
                ->value('opening_basis_at')
        );
        $this->assertDatabaseHas('wallet_cash_opening_audits', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'old_opening_cash' => 120.00,
            'new_opening_cash' => 180.00,
            'delta_amount' => 60.00,
            'changed_by' => $ctx['user']->id,
        ]);
        $this->assertDatabaseHas('wallet_cash_movements', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'movement_date' => $date,
            'notes' => 'AUTO_CC_OPENING',
            'amount' => 180.00,
            'direction' => 'in',
            'user_id' => $ctx['user']->id,
        ]);
    }

    public function test_save_as_opening_cash_reset_allows_same_day_reopen_and_recount(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(2)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 120,
            'counted_cash' => 200,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
            'notes' => 'Initial count',
        ]);

        $endUrl = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);
        $reopenUrl = route('domains.wallet-cash-ledger.reopen-shift', ['domain' => $ctx['domain']->name_slug]);
        $countedUrl = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($endUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'end_shift_action' => 'save_as_opening_cash',
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 200.00,
            'counted_cash' => 0.00,
            'counted_by' => null,
            'counted_at' => null,
            'is_closed' => 1,
        ]);

        $this->actingAs($ctx['user'])->post($reopenUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ])->assertRedirect();

        $this->actingAs($ctx['user'])->post($countedUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 230,
            'notes' => 'Recount after reopen',
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 200.00,
            'counted_cash' => 230.00,
            'counted_by' => $ctx['user']->id,
            'is_closed' => 0,
            'notes' => 'Recount after reopen',
        ]);
    }

    public function test_save_as_opening_cash_expected_uses_only_activity_after_basis_time(): void
    {
        $ctx = $this->seedContext();
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
        $date = now()->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
            'counted_cash' => 300,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-PRE-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 200,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 200,
            'transaction_date' => $date.' 09:00:00',
        ]);

        $endUrl = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);
        $reopenUrl = route('domains.wallet-cash-ledger.reopen-shift', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($endUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'end_shift_action' => 'save_as_opening_cash',
        ])->assertRedirect();

        $this->actingAs($ctx['user'])->post($reopenUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ])->assertRedirect();

        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-POST-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 50,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 50,
            'transaction_date' => $date.' 13:00:00',
        ]);

        $walletUrl = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ]);

        $this->actingAs($ctx['user'])->get($walletUrl)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->where('cashControl.opening_cash', 300)
                ->where('cashControl.paid_cash_sales', 50)
                ->where('cashControl.expected_cash', 350)
            );

        Carbon::setTestNow();
    }

    public function test_end_shift_cashout_requires_positive_counted_cash(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(17)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
            'counted_cash' => 0,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        $endUrl = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($endUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'end_shift_action' => 'cashout_now',
        ])->assertSessionHasErrors(['counted_cash']);
    }

    public function test_end_shift_requires_valid_action(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(4)->toDateString();
        $url = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
            'counted_cash' => 100,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ])->assertSessionHasErrors(['end_shift_action']);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'end_shift_action' => 'invalid',
        ])->assertSessionHasErrors(['end_shift_action']);
    }

    public function test_closed_shift_blocks_opening_counted_and_ledger_mutations(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(5)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
            'counted_cash' => 100,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
            'is_closed' => true,
            'closed_by' => $ctx['user']->id,
            'closed_at' => now(),
        ]);

        $openingUrl = route('domains.wallet-cash-ledger.opening-cash.store', ['domain' => $ctx['domain']->name_slug]);
        $countedUrl = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);
        $ledgerUrl = route('domains.wallet-cash-ledger.store', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($openingUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 999,
        ])->assertSessionHasErrors(['business_date']);

        $this->actingAs($ctx['user'])->post($countedUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'counted_cash' => 999,
        ])->assertSessionHasErrors(['business_date']);

        $this->actingAs($ctx['user'])->post($ledgerUrl, [
            'location_id' => $ctx['location']->id,
            'direction' => 'in',
            'amount' => 10,
            'kind' => 'adjustment',
            'movement_date' => $date,
        ])->assertSessionHasErrors(['business_date']);
    }

    public function test_wallet_index_exposes_shift_closed_flags_and_can_reopen_for_closer(): void
    {
        $ctx = $this->seedContext();
        $date = now()->subDays(6)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 100,
            'counted_cash' => 100,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
            'is_closed' => true,
            'closed_by' => $ctx['user']->id,
            'closed_at' => now(),
        ]);

        $url = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
        ]);

        $this->actingAs($ctx['user'])->get($url)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->where('cashControl.is_closed', true)
                ->where('cashControl.closed_by', $ctx['user']->id)
                ->where('cashControl.can_reopen', true)
            );
    }

    public function test_wallet_index_rejects_future_business_date(): void
    {
        $ctx = $this->seedContext();
        $futureDate = now()->addDay()->toDateString();

        $url = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $futureDate,
        ]);

        $this->actingAs($ctx['user'])->get($url)
            ->assertStatus(302)
            ->assertSessionHasErrors(['business_date']);
    }

    public function test_opening_counted_and_end_shift_reject_future_business_date(): void
    {
        $ctx = $this->seedContext();
        $futureDate = now()->addDay()->toDateString();

        $openingUrl = route('domains.wallet-cash-ledger.opening-cash.store', ['domain' => $ctx['domain']->name_slug]);
        $countedUrl = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);
        $endUrl = route('domains.wallet-cash-ledger.end-shift', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($openingUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $futureDate,
            'opening_cash' => 100,
        ])->assertSessionHasErrors(['business_date']);

        $this->actingAs($ctx['user'])->post($countedUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $futureDate,
            'counted_cash' => 100,
        ])->assertSessionHasErrors(['business_date']);

        $this->actingAs($ctx['user'])->post($endUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $futureDate,
            'end_shift_action' => 'cashout_now',
        ])->assertSessionHasErrors(['business_date']);
    }

    public function test_submit_counted_skips_daily_variance_when_bridge_matches_across_gap_days(): void
    {
        $ctx = $this->seedContext();
        $anchorDate = now()->subDays(12)->toDateString();
        $midDate = now()->subDays(11)->toDateString();
        $targetDate = now()->subDays(10)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $anchorDate,
            'opening_cash' => 0,
            'counted_cash' => 400,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-BR-1-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 100,
            'transaction_date' => $midDate.' 12:00:00',
        ]);
        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-BR-2-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 100,
            'transaction_date' => $targetDate.' 12:00:00',
        ]);

        $countedUrl = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);
        $this->actingAs($ctx['user'])->post($countedUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $targetDate,
            'counted_cash' => 600,
        ])->assertRedirect();

        $this->assertSame(
            0,
            WalletCashMovement::query()
                ->where('notes', 'AUTO_CC_COUNTED_VARIANCE')
                ->whereDate('movement_date', $targetDate)
                ->count()
        );
    }

    public function test_wallet_index_bridge_expected_includes_end_shift_cashout_in_span(): void
    {
        $ctx = $this->seedContext();
        $anchorDate = now()->subDays(20)->toDateString();
        $midDate = now()->subDays(19)->toDateString();
        $targetDate = now()->subDays(18)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $anchorDate,
            'opening_cash' => 0,
            'counted_cash' => 500,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        WalletCashMovement::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'direction' => 'out',
            'amount' => 250,
            'kind' => 'owner_draw',
            'movement_date' => $midDate,
            'user_id' => $ctx['user']->id,
            'notes' => 'AUTO_CC_ENDSHIFT_CASHOUT',
        ]);

        $walletUrl = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $targetDate,
        ]);

        $this->actingAs($ctx['user'])->get($walletUrl)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Wallet/Index')
                ->where('cashControl.bridge_anchor_business_date', $anchorDate)
                ->where('cashControl.bridge_expected_cash', 250)
            );
    }

    public function test_submit_counted_posts_variance_when_bridge_also_disagrees(): void
    {
        $ctx = $this->seedContext();
        $anchorDate = now()->subDays(15)->toDateString();
        $midDate = now()->subDays(14)->toDateString();
        $targetDate = now()->subDays(13)->toDateString();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $anchorDate,
            'opening_cash' => 0,
            'counted_cash' => 400,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-BR3-1-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 100,
            'transaction_date' => $midDate.' 10:00:00',
        ]);
        Sale::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'invoice_number' => 'INV-BR3-2-'.Str::random(6),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'location_id' => $ctx['location']->id,
            'total_amount' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 100,
            'transaction_date' => $targetDate.' 10:00:00',
        ]);

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $targetDate,
            'opening_cash' => 0,
        ]);

        $countedUrl = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);
        $this->actingAs($ctx['user'])->post($countedUrl, [
            'location_id' => $ctx['location']->id,
            'business_date' => $targetDate,
            'counted_cash' => 400,
        ])->assertRedirect();

        $this->assertSame(
            1,
            WalletCashMovement::query()
                ->where('notes', 'AUTO_CC_COUNTED_VARIANCE')
                ->whereDate('movement_date', $targetDate)
                ->count()
        );
        $this->assertDatabaseHas('wallet_cash_movements', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'movement_date' => $targetDate,
            'notes' => 'AUTO_CC_COUNTED_VARIANCE',
            'direction' => 'in',
            'amount' => 300.00,
        ]);
    }
}
