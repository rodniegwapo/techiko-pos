<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\User;
use App\Models\WalletCashMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletCashLedgerTest extends TestCase
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
     * @return array{domainA: Domain, domainB: Domain, locationA: InventoryLocation, locationA2: InventoryLocation, locationB: InventoryLocation, cardA: PaymentCardType, cardA2: PaymentCardType, cardB: PaymentCardType, user: User}
     */
    private function seedDomainsAndTypes(): array
    {
        $domainA = Domain::query()->create([
            'name' => 'Org A '.Str::random(4),
            'name_slug' => 'org-a-'.Str::lower(Str::random(6)),
        ]);
        $domainB = Domain::query()->create([
            'name' => 'Org B '.Str::random(4),
            'name_slug' => 'org-b-'.Str::lower(Str::random(6)),
        ]);

        $locationA = InventoryLocation::query()->create([
            'domain' => $domainA->name_slug,
            'name' => 'Org A Main',
            'code' => 'A'.Str::upper(Str::random(4)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        $locationA2 = InventoryLocation::query()->create([
            'domain' => $domainA->name_slug,
            'name' => 'Org A Branch',
            'code' => 'B'.Str::upper(Str::random(4)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);
        $locationB = InventoryLocation::query()->create([
            'domain' => $domainB->name_slug,
            'name' => 'Org B Main',
            'code' => 'C'.Str::upper(Str::random(4)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $cardA = PaymentCardType::query()->create([
            'domain' => $domainA->name_slug,
            'location_id' => $locationA->id,
            'name' => 'GCash A',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $cardA2 = PaymentCardType::query()->create([
            'domain' => $domainA->name_slug,
            'location_id' => $locationA2->id,
            'name' => 'Maya A2',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $cardB = PaymentCardType::query()->create([
            'domain' => $domainB->name_slug,
            'location_id' => $locationB->id,
            'name' => 'GCash B',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $user = User::factory()->create([
            'domain' => $domainA->name_slug,
            'location_id' => $locationA->id,
        ]);

        return compact('domainA', 'domainB', 'locationA', 'locationA2', 'locationB', 'cardA', 'cardA2', 'cardB', 'user');
    }

    public function test_store_movement_aborts_when_card_type_is_for_another_domain(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'in',
            'amount' => 150.50,
            'kind' => 'adjustment',
            'payment_card_type_id' => $s['cardB']->id,
            'movement_date' => now()->toDateString(),
            'notes' => 'Cross-domain attempt',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_store_movement_records_row_for_own_domain_card_type(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->from(route('domains.payment-card-types.index', [
            'domain' => $s['domainA']->name_slug,
        ]))->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'in',
            'amount' => 100,
            'kind' => 'cash_sale_topup',
            'payment_card_type_id' => $s['cardA']->id,
            'movement_date' => '2026-04-15',
            'notes' => 'Top up',
        ]);

        $response->assertRedirect();

        $row = WalletCashMovement::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($s['domainA']->name_slug, $row->domain);
        $this->assertSame($s['locationA']->id, $row->location_id);
        $this->assertSame($s['user']->id, $row->user_id);
        $this->assertSame('in', $row->direction);
        $this->assertSame($s['cardA']->id, $row->payment_card_type_id);
        $this->assertSame(100.0, (float) $row->amount);
        $this->assertSame('cash_sale_topup', $row->kind);
    }

    public function test_ledger_index_redirects_to_payment_wallet_preserving_query_string(): void
    {
        $s = $this->seedDomainsAndTypes();

        $from = route('domains.wallet-cash-ledger.index', ['domain' => $s['domainA']->name_slug]).'?page=2&rail=cash_register';

        $response = $this->actingAs($s['user'])->get($from);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $target = route('domains.payment-card-types.index', ['domain' => $s['domainA']->name_slug]).'?page=2&rail=cash_register';
        $this->assertSame($target, $location);
    }

    public function test_store_owner_draw_without_draw_source_fails_validation(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'out',
            'amount' => 50,
            'kind' => 'owner_draw',
            'payment_card_type_id' => $s['cardA']->id,
            'movement_date' => '2026-04-02',
            'notes' => 'Draw without source',
        ]);

        $response->assertSessionHasErrors(['draw_source']);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_store_owner_draw_from_cash_register_sets_null_payment_card_type_id(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->from(route('domains.payment-card-types.index', [
            'domain' => $s['domainA']->name_slug,
        ]))->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'out',
            'amount' => 50,
            'kind' => 'owner_draw',
            'draw_source' => 'cash_register',
            'movement_date' => '2026-04-02',
            'notes' => 'Owner draw cash',
        ]);

        $response->assertRedirect();

        $row = WalletCashMovement::query()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->payment_card_type_id);
        $this->assertSame('owner_draw', $row->kind);
        $this->assertSame('out', $row->direction);
        $this->assertSame(50.0, (float) $row->amount);
    }

    public function test_store_owner_draw_card_type_requires_payment_card_type(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'out',
            'amount' => 10,
            'kind' => 'owner_draw',
            'draw_source' => 'card_type',
            'movement_date' => '2026-04-02',
            'notes' => 'Missing rail',
        ]);

        $response->assertSessionHasErrors(['payment_card_type_id']);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_store_owner_draw_from_card_type_requires_valid_domain_card_type(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'out',
            'amount' => 25,
            'kind' => 'owner_draw',
            'draw_source' => 'card_type',
            'payment_card_type_id' => $s['cardA']->id,
            'movement_date' => '2026-04-02',
            'notes' => 'Draw from rail',
        ]);

        $response->assertRedirect();
        $row = WalletCashMovement::query()->first();
        $this->assertSame($s['cardA']->id, $row->payment_card_type_id);
    }

    public function test_store_owner_draw_cash_register_rejects_payment_card_type_id_field(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'out',
            'amount' => 10,
            'kind' => 'owner_draw',
            'draw_source' => 'cash_register',
            'payment_card_type_id' => $s['cardA']->id,
            'movement_date' => '2026-04-02',
        ]);

        $response->assertSessionHasErrors(['payment_card_type_id']);
        $this->assertSame(0, WalletCashMovement::query()->count());
    }

    public function test_store_movement_aborts_when_card_type_is_for_another_location_same_domain(): void
    {
        $s = $this->seedDomainsAndTypes();

        $url = route('domains.wallet-cash-ledger.store', ['domain' => $s['domainA']->name_slug]);

        $response = $this->actingAs($s['user'])->post($url, [
            'location_id' => $s['locationA']->id,
            'direction' => 'in',
            'amount' => 75,
            'kind' => 'adjustment',
            'payment_card_type_id' => $s['cardA2']->id,
            'movement_date' => now()->toDateString(),
        ]);

        $response->assertForbidden();
        $this->assertSame(0, WalletCashMovement::query()->count());
    }
}
