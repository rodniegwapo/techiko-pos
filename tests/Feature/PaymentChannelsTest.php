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
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payment channels: card terminals, e-wallets (GCash, Maya…) and banks. Card, e-wallet and bank sales
 * name a channel of that kind at the sale's store; e-wallet and bank sales can keep a reference no.
 */
class PaymentChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
            ValidateCsrfToken::class,
        ]);
        $this->seed(ProductSoldTypeSeeder::class);
    }

    /** @return array{domain: Domain, store: InventoryLocation, other: InventoryLocation, user: User, product: Product, channels: array<string, PaymentCardType>} */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Channels Org',
            'name_slug' => 'pch-org-'.Str::lower(Str::random(8)),
        ]);

        $makeStore = fn (string $name, bool $default) => InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => $name,
            'code' => 'PC-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => $default,
        ]);
        $store = $makeStore('Main', true);
        $other = $makeStore('Branch', false);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $store->id,
        ]);

        $product = Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => Category::factory()->create(['domain' => $domain->name_slug])->id,
            'sold_type' => 'Piece',
            'track_inventory' => true,
            'price' => 100,
        ]);
        $product->activeLocations()->attach($store->id, ['is_active' => true]);
        ProductInventory::query()->create([
            'product_id' => $product->id,
            'location_id' => $store->id,
            'quantity_on_hand' => 50,
            'quantity_reserved' => 0,
            'quantity_available' => 50,
        ]);

        $channel = fn (string $name, string $kind, InventoryLocation $at) => PaymentCardType::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $at->id,
            'name' => $name,
            'kind' => $kind,
            'is_active' => true,
        ]);

        return [
            'domain' => $domain,
            'store' => $store,
            'other' => $other,
            'user' => $user,
            'product' => $product,
            'channels' => [
                'visa' => $channel('Visa terminal', 'card', $store),
                'gcash' => $channel('GCash', 'ewallet', $store),
                'bdo' => $channel('BDO', 'bank', $store),
                'branchGcash' => $channel('GCash', 'ewallet', $other),
            ],
        ];
    }

    private function pendingSale(array $ctx): Sale
    {
        $sale = Sale::query()->create([
            'user_id' => $ctx['user']->id,
            'location_id' => $ctx['store']->id,
            'domain' => $ctx['domain']->name_slug,
            'payment_status' => 'pending',
            'invoice_number' => Str::upper(Str::random(10)),
            'transaction_date' => now(),
            'grand_total' => 0,
            'total_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'payment_method' => 'cash',
        ]);
        $sale->saleItems()->create([
            'product_id' => $ctx['product']->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount' => 0,
        ]);
        $sale->recalcTotals();

        return $sale;
    }

    private function pay(array $ctx, Sale $sale, array $body)
    {
        return $this->actingAs($ctx['user'])->postJson(route('domains.sales.payment.store', [
            'domain' => $ctx['domain']->name_slug,
            'sale' => $sale->id,
        ]), $body);
    }

    public function test_an_e_wallet_sale_keeps_its_channel_and_reference(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'e-wallet',
            'payment_card_type_id' => $ctx['channels']['gcash']->id,
            'payment_reference' => ' 1234 5678 ',
        ])->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('e-wallet', $sale->payment_method);
        $this->assertSame($ctx['channels']['gcash']->id, $sale->payment_card_type_id);
        $this->assertSame('1234 5678', $sale->payment_reference);
    }

    public function test_a_bank_sale_is_accepted(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'bank',
            'payment_card_type_id' => $ctx['channels']['bdo']->id,
        ])->assertOk();

        $this->assertSame('bank', $sale->fresh()->payment_method);
    }

    public function test_the_channel_must_be_of_the_payment_methods_kind(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, ['payment_method' => 'e-wallet'])
            ->assertStatus(422)
            ->assertJsonPath('errors.payment_card_type_id.0', 'Choose the e-wallet used for this payment.');

        // A card terminal can't take an e-wallet payment, nor a bank a card payment.
        $this->pay($ctx, $sale, ['payment_method' => 'e-wallet', 'payment_card_type_id' => $ctx['channels']['visa']->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.payment_card_type_id.0', "That e-wallet isn't available at this store.");
        $this->pay($ctx, $sale, ['payment_method' => 'card', 'payment_card_type_id' => $ctx['channels']['bdo']->id])
            ->assertStatus(422);

        $this->assertSame('pending', $sale->fresh()->payment_status);
    }

    public function test_another_stores_channel_is_refused(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'e-wallet',
            'payment_card_type_id' => $ctx['channels']['branchGcash']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('payment_card_type_id');
    }

    public function test_a_card_sale_keeps_no_reference(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'card',
            'payment_card_type_id' => $ctx['channels']['visa']->id,
            'payment_reference' => 'ignored',
        ])->assertOk();

        $this->assertNull($sale->fresh()->payment_reference);
    }

    public function test_an_offline_e_wallet_sale_syncs_with_its_channel(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])->postJson(route('domains.sales.offline-sync', ['domain' => $ctx['domain']->name_slug]), [
            'sales' => [[
                'client_mutation_id' => (string) Str::uuid(),
                'payload' => [
                    'items' => [['product_id' => $ctx['product']->id, 'quantity' => 1, 'unit_price' => 100]],
                    'payment_method' => 'e-wallet',
                    'payment_card_type_id' => $ctx['channels']['gcash']->id,
                    'payment_reference' => 'GC-99',
                    'location_id' => $ctx['store']->id,
                    'cashier_user_id' => $ctx['user']->id,
                ],
            ]],
        ])->assertOk();

        $saleId = collect($response->json('results'))->first()['sale_id'] ?? null;
        $this->assertNotNull($saleId, json_encode($response->json()));
        $sale = Sale::query()->findOrFail($saleId);
        $this->assertSame('e-wallet', $sale->payment_method);
        $this->assertSame($ctx['channels']['gcash']->id, $sale->payment_card_type_id);
        $this->assertSame('GC-99', $sale->payment_reference);
    }

    public function test_a_channel_is_a_card_unless_told_otherwise_and_the_list_filters_by_kind(): void
    {
        $ctx = $this->seedContext();
        $base = ['domain' => $ctx['domain']->name_slug];

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.payment-card-types.store', $base), ['name' => 'Mastercard', 'location_id' => $ctx['store']->id])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'card');

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.payment-card-types.store', $base), ['name' => 'Maya', 'kind' => 'ewallet', 'location_id' => $ctx['store']->id])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'ewallet');

        $names = collect($this->actingAs($ctx['user'])
            ->getJson(route('domains.payment-card-types.list', $base).'?kind=ewallet&location_id='.$ctx['store']->id)
            ->assertOk()
            ->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['GCash', 'Maya'], $names);
    }

    public function test_money_for_an_e_wallet_counts_only_its_e_wallet_sales(): void
    {
        $ctx = $this->seedContext();
        $gcash = $ctx['channels']['gcash'];

        $this->pay($ctx, $this->pendingSale($ctx), ['payment_method' => 'e-wallet', 'payment_card_type_id' => $gcash->id])->assertOk();
        $this->pay($ctx, $this->pendingSale($ctx), ['payment_method' => 'bank', 'payment_card_type_id' => $ctx['channels']['bdo']->id])->assertOk();

        $this->actingAs($ctx['user'])
            ->getJson(route('domains.payment-card-types.money', [
                'domain' => $ctx['domain']->name_slug,
                'paymentCardType' => $gcash->id,
            ]).'?location_id='.$ctx['store']->id)
            ->assertOk()
            ->assertJsonPath('today_total', 100)
            ->assertJsonPath('history.total', 1);
    }
}
