<?php

namespace Tests\Feature;

use App\Events\OrderUpdated;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer-facing display shows its own store's open order, through a link carrying the
 * store's secret, and never another business's.
 */
class CustomerDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{store: InventoryLocation, sale: Sale} */
    private function storeWithOpenOrder(string $name, float $total): array
    {
        $domain = Domain::query()->create(['name' => $name, 'name_slug' => Str::slug($name).'-'.Str::lower(Str::random(5))]);
        $store = InventoryLocation::query()->create([
            'domain' => $domain->name_slug, 'name' => $name.' Store', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $user = User::factory()->create(['domain' => $domain->name_slug, 'location_id' => $store->id]);
        $sale = Sale::query()->create([
            'domain' => $domain->name_slug, 'user_id' => $user->id, 'location_id' => $store->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)), 'payment_status' => 'pending',
            'total_amount' => $total, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $total,
            'transaction_date' => now(),
        ]);

        return ['store' => $store, 'sale' => $sale];
    }

    public function test_a_display_shows_only_its_own_stores_order(): void
    {
        $mine = $this->storeWithOpenOrder('Jollibee', 150);
        // Another business's order, opened later: it used to be what every display showed.
        $theirs = $this->storeWithOpenOrder('Mang Inasal', 999);
        $theirs['sale']->touch();

        $this->getJson('/api/orders/recent-pending?display='.$mine['store']->displayToken())
            ->assertOk()
            ->assertJsonPath('order.id', $mine['sale']->id);

        // No link, or a made-up one: nothing.
        $this->getJson('/api/orders/recent-pending')->assertNotFound();
        $this->getJson('/api/orders/recent-pending?display='.Str::random(40))->assertNotFound();
    }

    public function test_an_order_is_shown_only_on_its_stores_display(): void
    {
        $mine = $this->storeWithOpenOrder('Jollibee', 150);
        $theirs = $this->storeWithOpenOrder('Mang Inasal', 999);
        $token = $mine['store']->displayToken();

        $this->getJson("/api/orders/{$mine['sale']->id}/view?display={$token}")->assertOk();
        $this->getJson("/api/orders/{$theirs['sale']->id}/view?display={$token}")->assertNotFound();
        $this->getJson("/api/orders/{$mine['sale']->id}/view")->assertNotFound();
    }

    public function test_order_updates_go_only_to_that_stores_display(): void
    {
        $mine = $this->storeWithOpenOrder('Jollibee', 150);
        $theirs = $this->storeWithOpenOrder('Mang Inasal', 999);

        $channel = (new OrderUpdated($mine['sale']))->broadcastOn()->name;

        $this->assertSame('customer-display.'.$mine['store']->displayToken(), $channel);
        $this->assertNotSame((new OrderUpdated($theirs['sale']))->broadcastOn()->name, $channel);
        $this->assertNotSame('order', $channel);
    }
}
