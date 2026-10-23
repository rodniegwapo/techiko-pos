<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Inertia;
use Tests\TestCase;

/**
 * "Amount in a payment channel not reflecting when moving money": each channel shows what it
 * holds (its ledger lines plus card/bank sales through it), and a channel's lines no longer move
 * the cash drawer's expected or running cash.
 */
class ChannelBalancesTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private PaymentCardType $gcash;

    private PaymentCardType $terminal;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create(['name' => 'Channel Org', 'name_slug' => 'channel-org-'.Str::lower(Str::random(6))]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main', 'code' => 'M'.Str::upper(Str::random(4)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $channel = fn (string $name, string $kind) => PaymentCardType::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id,
            'name' => $name, 'kind' => $kind, 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->gcash = $channel('GCash', 'ewallet');
        $this->terminal = $channel('BDO Terminal', 'card');
        $this->user = User::factory()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'is_super_user' => true,
        ]);
    }

    private function movement(?PaymentCardType $channel, string $direction, float $amount, string $kind = 'adjustment'): void
    {
        WalletCashMovement::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id,
            'payment_card_type_id' => $channel?->id, 'direction' => $direction, 'amount' => $amount,
            'kind' => $kind, 'movement_date' => now()->toDateString(), 'user_id' => $this->user->id,
        ]);
    }

    private function sale(string $method, float $total, ?PaymentCardType $channel, string $status = 'paid'): void
    {
        Sale::query()->create([
            'domain' => $this->domain->name_slug, 'user_id' => $this->user->id, 'location_id' => $this->store->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)), 'payment_method' => $method,
            'payment_card_type_id' => $channel?->id, 'payment_status' => $status,
            'total_amount' => $total, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $total,
            'transaction_date' => now(),
        ]);
    }

    private function page()
    {
        return $this->actingAs($this->user)->get(route('domains.wallet.balances', [
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id,
        ]))->assertOk();
    }

    public function test_each_channel_shows_its_ledger_lines_and_card_sales(): void
    {
        $this->movement($this->gcash, 'in', 500, 'ewallet_transfer_in');
        $this->movement($this->gcash, 'out', 120, 'owner_draw');
        $this->sale('card', 300, $this->terminal);
        $this->sale('card', 999, $this->terminal, 'voided');

        $this->page()->assertInertia(fn (Inertia $page) => $page
            ->component('Wallet/Balances')
            ->where('channelBalances', fn ($rows) => collect($rows)->pluck('balance', 'name')->all() === [
                'BDO Terminal' => 300, 'GCash' => 380,
            ]));
    }

    public function test_a_channel_line_leaves_the_drawer_alone(): void
    {
        $this->movement(null, 'in', 1000);
        $this->movement($this->gcash, 'out', 200, 'owner_draw');
        $this->movement($this->gcash, 'in', 50, 'ewallet_transfer_in');

        $this->page()->assertInertia(fn (Inertia $page) => $page
            // The drawer card shows today's Expected; the channel lines don't touch it.
            ->where('drawerExpected', 1000)
            ->where('channelBalances.0.name', 'BDO Terminal')
            ->where('channelBalances.1.balance', -150));
    }

    public function test_setting_the_actual_balance_records_the_difference(): void
    {
        $this->movement($this->gcash, 'in', 380);

        // The page posts the difference from what GCash shows (500) as an adjustment.
        $this->actingAs($this->user)->post(route('domains.wallet-cash-ledger.store', ['domain' => $this->domain->name_slug]), [
            'location_id' => $this->store->id, 'direction' => 'in', 'amount' => 120, 'kind' => 'adjustment',
            'payment_card_type_id' => $this->gcash->id, 'movement_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->page()->assertInertia(fn (Inertia $page) => $page
            ->where('channelBalances.1.balance', 500)
            ->where('drawerExpected', 0));
    }

    public function test_an_e_wallet_transfer_names_its_channel_and_direction(): void
    {
        $url = route('domains.wallet-cash-ledger.store', ['domain' => $this->domain->name_slug]);
        $base = ['location_id' => $this->store->id, 'amount' => 70, 'movement_date' => now()->toDateString()];

        $this->actingAs($this->user)->post($url, $base + ['kind' => 'ewallet_transfer_in', 'direction' => 'in'])
            ->assertSessionHasErrors('payment_card_type_id');
        $this->actingAs($this->user)->post($url, $base + ['kind' => 'ewallet_transfer_in', 'direction' => 'out', 'payment_card_type_id' => $this->gcash->id])
            ->assertSessionHasErrors('direction');
        $this->actingAs($this->user)->post($url, $base + ['kind' => 'ewallet_transfer_out', 'direction' => 'out', 'payment_card_type_id' => $this->gcash->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, WalletCashMovement::query()->count());
    }
}
