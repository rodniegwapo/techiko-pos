<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;
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
        $url = route('domains.wallet-cash-ledger.opening-cash.store', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
            'opening_cash' => 1000,
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
            'opening_cash' => 1000.00,
            'opening_source' => 'manual',
        ]);
        $this->assertDatabaseHas('wallet_cash_opening_audits', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
            'old_opening_cash' => null,
            'new_opening_cash' => 1000.00,
            'delta_amount' => 1000.00,
            'changed_by' => $ctx['user']->id,
        ]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
            'opening_cash' => 1500,
            'reason' => 'Owner changed float after recount',
        ])->assertRedirect();

        $this->assertSame(1, WalletCashReconciliation::query()->count());
        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
            'opening_cash' => 1500.00,
            'opening_source' => 'manual',
        ]);
        $this->assertSame(2, WalletCashOpeningAudit::query()->count());
        $this->assertDatabaseHas('wallet_cash_opening_audits', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
            'old_opening_cash' => 1000.00,
            'new_opening_cash' => 1500.00,
            'delta_amount' => 500.00,
            'changed_by' => $ctx['user']->id,
            'reason' => 'Owner changed float after recount',
        ]);
    }

    public function test_submit_counted_cash_records_user_and_timestamp(): void
    {
        $ctx = $this->seedContext();
        $url = route('domains.wallet-cash-ledger.counted-cash.store', ['domain' => $ctx['domain']->name_slug]);

        $this->actingAs($ctx['user'])->post($url, [
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
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
    }

    public function test_wallet_index_returns_cash_control_formula_values(): void
    {
        $ctx = $this->seedContext();
        $date = '2026-05-01';

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'opening_cash' => 1000,
            'counted_cash' => 1210,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
            'notes' => 'Night count',
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
            );
    }

    public function test_wallet_index_suggests_opening_from_previous_counted_cash_when_current_not_saved(): void
    {
        $ctx = $this->seedContext();

        WalletCashReconciliation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-01',
            'opening_cash' => 800,
            'counted_cash' => 900,
            'counted_by' => $ctx['user']->id,
            'counted_at' => now(),
        ]);

        $url = route('domains.payment-card-types.index', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => '2026-05-02',
        ]);

        $this->actingAs($ctx['user'])->get($url)
            ->assertOk()
            ->assertInertia(fn (Inertia $page) => $page
                ->component('Wallet/Index')
                ->where('cashControl.business_date', '2026-05-02')
                ->where('cashControl.opening_is_saved', false)
                ->where('cashControl.opening_suggestion', 900)
                ->where('cashControl.suggestion_source_date', '2026-05-01')
                ->where('cashControl.opening_cash', 900)
            );
    }

    public function test_end_shift_is_blocked_when_counted_cash_is_missing(): void
    {
        $ctx = $this->seedContext();
        $date = '2026-05-03';

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
        ])->assertSessionHasErrors(['counted_cash']);
    }

    public function test_end_shift_closes_shift_and_reopen_is_same_user_only(): void
    {
        $ctx = $this->seedContext();
        $date = '2026-05-04';
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
        ])->assertRedirect();

        $this->assertDatabaseHas('wallet_cash_reconciliations', [
            'domain' => $ctx['domain']->name_slug,
            'location_id' => $ctx['location']->id,
            'business_date' => $date,
            'is_closed' => 1,
            'closed_by' => $ctx['user']->id,
        ]);

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

    public function test_closed_shift_blocks_opening_counted_and_ledger_mutations(): void
    {
        $ctx = $this->seedContext();
        $date = '2026-05-05';

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
        $date = '2026-05-06';

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
}
