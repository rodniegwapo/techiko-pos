<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use App\Support\Wallet\WalletCashDailyExpected;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A sale paid in parts: cash + GCash, card + credit… Each part is kept; only cash may come to more
 * than the total (the rest is change), and each way's totals count only its own part.
 */
class SplitPaymentTest extends TestCase
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

    /** @return array{domain: Domain, store: InventoryLocation, user: User, product: Product, channels: array<string, PaymentCardType>} */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Split Org',
            'name_slug' => 'spl-org-'.Str::lower(Str::random(8)),
        ]);

        $store = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => 'SP-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

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
            'quantity_on_hand' => 100,
            'quantity_reserved' => 0,
            'quantity_available' => 100,
        ]);

        $channel = fn (string $name, string $kind) => PaymentCardType::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $store->id,
            'name' => $name,
            'kind' => $kind,
            'is_active' => true,
        ]);

        return [
            'domain' => $domain,
            'store' => $store,
            'user' => $user,
            'product' => $product,
            'channels' => [
                'visa' => $channel('Visa terminal', 'card'),
                'gcash' => $channel('GCash', 'ewallet'),
                'maya' => $channel('Maya', 'ewallet'),
            ],
        ];
    }

    /** A pending sale for 5 × ₱100 = ₱500. */
    private function pendingSale(array $ctx, int $quantity = 5): Sale
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
            'quantity' => $quantity,
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

    private function cashAndGcash(array $ctx, float $cash = 250, float $gcash = 300): array
    {
        return [
            'payment_method' => 'split',
            'payments' => [
                ['method' => 'cash', 'amount' => $cash],
                ['method' => 'e-wallet', 'amount' => $gcash, 'payment_card_type_id' => $ctx['channels']['gcash']->id, 'payment_reference' => 'GC-1'],
            ],
        ];
    }

    public function test_cash_and_e_wallet_parts_are_kept_with_change_off_the_cash(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, $this->cashAndGcash($ctx))->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('split', $sale->payment_method);
        $this->assertNull($sale->payment_card_type_id);

        $parts = $sale->payments->keyBy('method');
        $this->assertEquals(200, (float) $parts['cash']->amount, 'cash paid what was left; ₱50 was change');
        $this->assertEquals(250, (float) $parts['cash']->tendered);
        $this->assertEquals(300, (float) $parts['e-wallet']->amount);
        $this->assertSame($ctx['channels']['gcash']->id, $parts['e-wallet']->payment_card_type_id);
        $this->assertSame('GC-1', $parts['e-wallet']->reference);
    }

    public function test_two_e_wallets_can_share_a_sale(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'split',
            'payments' => [
                ['method' => 'e-wallet', 'amount' => 200, 'payment_card_type_id' => $ctx['channels']['gcash']->id],
                ['method' => 'e-wallet', 'amount' => 300, 'payment_card_type_id' => $ctx['channels']['maya']->id],
            ],
        ])->assertOk();

        $this->assertCount(2, $sale->fresh()->payments);
    }

    public function test_parts_short_of_the_total_are_refused(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, $this->cashAndGcash($ctx, cash: 100, gcash: 300))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments');

        $this->assertSame('pending', $sale->fresh()->payment_status);
        $this->assertCount(0, $sale->fresh()->payments);
    }

    public function test_only_cash_may_come_to_more_than_the_total(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, $this->cashAndGcash($ctx, cash: 100, gcash: 600))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments');
    }

    public function test_each_part_needs_a_channel_of_its_kind_at_this_store(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'split',
            'payments' => [
                ['method' => 'cash', 'amount' => 200],
                ['method' => 'e-wallet', 'amount' => 300, 'payment_card_type_id' => $ctx['channels']['visa']->id],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('payments.1.payment_card_type_id');

        $this->assertSame('pending', $sale->fresh()->payment_status);
    }

    public function test_cash_can_be_used_once(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'split',
            'payments' => [
                ['method' => 'cash', 'amount' => 200],
                ['method' => 'cash', 'amount' => 300],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('payments');
    }

    public function test_a_credit_part_goes_on_the_customers_account(): void
    {
        $ctx = $this->seedContext();
        $customer = Customer::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'name' => 'Maria Santos',
            'credit_enabled' => true,
            'credit_limit' => 1000,
            'credit_balance' => 0,
            'credit_terms_days' => 30,
            'tier' => 'bronze',
            'loyalty_points' => 0,
            'lifetime_spent' => 0,
        ]);
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'split',
            'customer_id' => $customer->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 200],
                ['method' => 'credit', 'amount' => 300],
            ],
        ])->assertOk()->assertJsonStructure(['credit_results' => ['transaction_id', 'credit_balance']]);

        $sale->refresh();
        $this->assertSame('split', $sale->payment_method);
        $this->assertTrue((bool) $sale->is_credit_sale);
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertEquals(300, (float) CreditTransaction::where('sale_id', $sale->id)->sole()->amount);
        $this->assertEquals(300, (float) $customer->fresh()->credit_balance);
    }

    public function test_a_credit_part_needs_a_customer(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);

        $this->pay($ctx, $sale, [
            'payment_method' => 'split',
            'payments' => [
                ['method' => 'cash', 'amount' => 200],
                ['method' => 'credit', 'amount' => 300],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('customer_id');

        $this->assertSame('pending', $sale->fresh()->payment_status);
    }

    public function test_the_drawer_expects_only_the_cash_part(): void
    {
        $ctx = $this->seedContext();
        $this->pay($ctx, $this->pendingSale($ctx), $this->cashAndGcash($ctx))->assertOk();
        $this->pay($ctx, $this->pendingSale($ctx, quantity: 1), ['payment_method' => 'cash'])->assertOk();

        $expected = WalletCashDailyExpected::compute($ctx['domain']->name_slug, $ctx['store']->id, now()->toDateString());

        $this->assertEquals(300, $expected['paid_cash_sales'], '₱200 cash part + a ₱100 cash sale');
    }

    public function test_an_e_wallets_money_counts_only_its_part(): void
    {
        $ctx = $this->seedContext();
        $gcash = $ctx['channels']['gcash'];
        $this->pay($ctx, $this->pendingSale($ctx), $this->cashAndGcash($ctx))->assertOk();

        $this->actingAs($ctx['user'])
            ->getJson(route('domains.payment-card-types.money', [
                'domain' => $ctx['domain']->name_slug,
                'paymentCardType' => $gcash->id,
            ]).'?location_id='.$ctx['store']->id)
            ->assertOk()
            ->assertJsonPath('today_total', 300)
            ->assertJsonPath('history.total', 1)
            ->assertJsonPath('history.data.0.grand_total', 300)
            ->assertJsonPath('history.data.0.is_split', true);
    }

    public function test_sales_history_shows_the_parts_and_finds_the_sale_by_either_way(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->pendingSale($ctx);
        $this->pay($ctx, $sale, $this->cashAndGcash($ctx))->assertOk();

        $this->actingAs($ctx['user'])
            ->getJson(route('domains.sales-history.show', ['domain' => $ctx['domain']->name_slug, 'sale' => $sale->id]))
            ->assertOk()
            ->assertJsonPath('payment_method', 'split')
            ->assertJsonCount(2, 'payments')
            ->assertJsonPath('payments.1.label', 'GCash')
            ->assertJsonPath('payments.1.amount', 300);

        $this->actingAs($ctx['user'])
            ->get(route('domains.sales-history.index', ['domain' => $ctx['domain']->name_slug, 'payment_method' => 'e-wallet']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $sale->id)
                ->where('items.data.0.payment_card_type', 'Cash 200.00 + GCash 300.00'));
    }

    public function test_an_offline_split_sale_syncs_with_its_parts(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])->postJson(route('domains.sales.offline-sync', ['domain' => $ctx['domain']->name_slug]), [
            'sales' => [[
                'client_mutation_id' => (string) Str::uuid(),
                'payload' => [
                    'items' => [['product_id' => $ctx['product']->id, 'quantity' => 5, 'unit_price' => 100]],
                    'payment_method' => 'split',
                    'payments' => [
                        ['method' => 'cash', 'amount' => 500],
                        ['method' => 'e-wallet', 'amount' => 100, 'payment_card_type_id' => $ctx['channels']['gcash']->id],
                    ],
                    'location_id' => $ctx['store']->id,
                    'cashier_user_id' => $ctx['user']->id,
                ],
            ]],
        ])->assertOk();

        $saleId = collect($response->json('results'))->first()['sale_id'] ?? null;
        $this->assertNotNull($saleId, json_encode($response->json()));

        $parts = Sale::query()->findOrFail($saleId)->payments->keyBy('method');
        $this->assertEquals(400, (float) $parts['cash']->amount);
        $this->assertEquals(500, (float) $parts['cash']->tendered);
        $this->assertEquals(100, (float) $parts['e-wallet']->amount);
    }

    public function test_an_offline_split_sale_cannot_use_credit(): void
    {
        $ctx = $this->seedContext();

        $this->actingAs($ctx['user'])->postJson(route('domains.sales.offline-sync', ['domain' => $ctx['domain']->name_slug]), [
            'sales' => [[
                'client_mutation_id' => (string) Str::uuid(),
                'payload' => [
                    'items' => [['product_id' => $ctx['product']->id, 'quantity' => 5, 'unit_price' => 100]],
                    'payment_method' => 'split',
                    'payments' => [
                        ['method' => 'cash', 'amount' => 200],
                        ['method' => 'credit', 'amount' => 300],
                    ],
                    'location_id' => $ctx['store']->id,
                    'cashier_user_id' => $ctx['user']->id,
                ],
            ]],
        ])->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
    }
}
