<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserPin;
use App\Models\VoidLog;
use App\Services\ProductModifierService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Voiding a whole completed sale from Sales History: manager PIN and a reason; the stock goes
 * back, credit and loyalty are reversed, and the sale drops out of the totals but stays listed.
 */
class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '4321';

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

    /** @return array{domain: Domain, store: InventoryLocation, user: User, product: Product} */
    private function seedContext(string $name = 'Void Org'): array
    {
        $domain = Domain::query()->create([
            'name' => $name,
            'name_slug' => 'void-org-'.Str::lower(Str::random(8)),
        ]);

        $store = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => 'VO-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        // An admin with a PIN: approves their own voids.
        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $store->id,
        ]);
        $user->assignRole(Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        UserPin::query()->create(['user_id' => $user->id, 'pin_code' => Hash::make(self::PIN)]);

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
            'quantity_on_hand' => 20,
            'quantity_reserved' => 0,
            'quantity_available' => 20,
        ]);

        return compact('domain', 'store', 'user', 'product');
    }

    /** A sale for 5 × ₱100, paid through the real checkout so stock, credit and loyalty move. */
    private function paidSale(array $ctx, array $body = ['payment_method' => 'cash'], int $quantity = 5): Sale
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

        $this->actingAs($ctx['user'])->postJson(route('domains.sales.payment.store', [
            'domain' => $ctx['domain']->name_slug,
            'sale' => $sale->id,
        ]), $body)->assertOk();

        return $sale->fresh();
    }

    private function void(array $ctx, Sale $sale, array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $ctx['user'])->postJson(route('domains.sales-history.void', [
            'domain' => $ctx['domain']->name_slug,
            'sale' => $sale->id,
        ]), $body + ['pin_code' => self::PIN, 'reason' => 'Wrong items rung up']);
    }

    private function stock(array $ctx): float
    {
        return (float) ProductInventory::query()
            ->where('product_id', $ctx['product']->id)
            ->where('location_id', $ctx['store']->id)
            ->value('quantity_on_hand');
    }

    private function creditCustomer(array $ctx, array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'domain' => $ctx['domain']->name_slug,
            'name' => 'Maria Santos',
            'credit_enabled' => true,
            'credit_limit' => 5000,
            'credit_balance' => 0,
            'credit_terms_days' => 30,
            'tier' => 'bronze',
            'loyalty_points' => 0,
            'lifetime_spent' => 0,
        ], $overrides));
    }

    public function test_voiding_a_sale_returns_its_stock_and_marks_it_voided(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->paidSale($ctx);
        $this->assertEquals(15, $this->stock($ctx));

        $this->void($ctx, $sale)->assertOk();

        $sale->refresh();
        $this->assertSame('voided', $sale->payment_status);
        $this->assertNotNull($sale->voided_at);
        $this->assertSame($ctx['user']->id, (int) $sale->voided_by);
        $this->assertSame($ctx['user']->id, (int) $sale->void_approved_by);
        $this->assertSame('Wrong items rung up', $sale->void_reason);
        $this->assertEquals(20, $this->stock($ctx));

        // The Void Logs page lists what was on the receipt.
        $log = VoidLog::query()->where('sale_item_id', $sale->saleItems()->value('id'))->sole();
        $this->assertStringContainsString('Wrong items rung up', $log->reason);
    }

    public function test_a_voided_sale_stays_listed_but_out_of_the_totals(): void
    {
        $ctx = $this->seedContext();
        $kept = $this->paidSale($ctx);
        $voided = $this->paidSale($ctx, quantity: 2);
        $this->void($ctx, $voided)->assertOk();

        $this->actingAs($ctx['user'])
            ->get(route('domains.sales-history.index', ['domain' => $ctx['domain']->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                ->where('summary.sales_count', 1)
                ->where('summary.net', round((float) $kept->grand_total, 2))
                ->where('summary.voided_sales', 1)
            );

        $this->actingAs($ctx['user'])
            ->getJson(route('domains.sales-history.show', ['domain' => $ctx['domain']->name_slug, 'sale' => $voided->id]))
            ->assertOk()
            ->assertJsonPath('payment_status', 'voided')
            ->assertJsonPath('void.reason', 'Wrong items rung up')
            ->assertJsonPath('can_void', false);
    }

    public function test_a_wrong_pin_changes_nothing(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->paidSale($ctx);

        $this->void($ctx, $sale, ['pin_code' => '0000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin_code');

        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertEquals(15, $this->stock($ctx));
    }

    public function test_a_reason_is_required(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->paidSale($ctx);

        $this->void($ctx, $sale, ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_a_sale_cannot_be_voided_twice(): void
    {
        $ctx = $this->seedContext();
        $sale = $this->paidSale($ctx);

        $this->void($ctx, $sale)->assertOk();
        $this->void($ctx, $sale)->assertStatus(422)->assertJsonValidationErrors('sale');

        // Stock went back once, not twice.
        $this->assertEquals(20, $this->stock($ctx));
    }

    public function test_an_open_cart_cannot_be_voided_here(): void
    {
        $ctx = $this->seedContext();
        $cart = Sale::query()->create([
            'user_id' => $ctx['user']->id,
            'location_id' => $ctx['store']->id,
            'domain' => $ctx['domain']->name_slug,
            'payment_status' => 'pending',
            'invoice_number' => Str::upper(Str::random(10)),
            'transaction_date' => now(),
        ]);

        $this->void($ctx, $cart)->assertNotFound();
    }

    public function test_another_organizations_sale_cannot_be_voided(): void
    {
        $ctx = $this->seedContext();
        $other = $this->seedContext('Other Org');
        $theirs = $this->paidSale($other);

        $this->actingAs($ctx['user'])->postJson(route('domains.sales-history.void', [
            'domain' => $ctx['domain']->name_slug,
            'sale' => $theirs->id,
        ]), ['pin_code' => self::PIN, 'reason' => 'x'])->assertNotFound();

        $this->assertSame('paid', $theirs->fresh()->payment_status);
    }

    public function test_voiding_a_credit_sale_cancels_the_charge(): void
    {
        $ctx = $this->seedContext();
        $customer = $this->creditCustomer($ctx);
        $sale = $this->paidSale($ctx, ['payment_method' => 'credit', 'customer_id' => $customer->id]);
        $this->assertEquals(500, (float) $customer->fresh()->credit_balance);

        $this->void($ctx, $sale)->assertOk();

        $this->assertEquals(0, (float) $customer->fresh()->credit_balance);
        $charge = CreditTransaction::query()->where('sale_id', $sale->id)->where('transaction_type', 'credit')->sole();
        $this->assertNotNull($charge->paid_at);
        $this->assertEquals(0, $charge->remaining);
        $this->assertFalse($charge->is_overdue);
        $this->assertEquals(-500, (float) CreditTransaction::query()
            ->where('sale_id', $sale->id)->where('transaction_type', 'adjustment')->sole()->amount);
    }

    public function test_a_credit_sale_already_paid_on_is_refused(): void
    {
        $ctx = $this->seedContext();
        $customer = $this->creditCustomer($ctx);
        $sale = $this->paidSale($ctx, ['payment_method' => 'credit', 'customer_id' => $customer->id]);
        CreditTransaction::query()->where('sale_id', $sale->id)->update(['paid_amount' => 100]);

        $this->void($ctx, $sale)->assertStatus(422)->assertJsonValidationErrors('sale');

        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertEquals(15, $this->stock($ctx));
    }

    public function test_voiding_takes_back_points_earned_and_returns_points_spent(): void
    {
        $ctx = $this->seedContext();
        $customer = $this->creditCustomer($ctx, ['loyalty_points' => 0]);
        $sale = $this->paidSale($ctx, ['payment_method' => 'cash', 'customer_id' => $customer->id]);

        $customer->refresh();
        $earned = (int) $customer->loyalty_points;
        $this->assertGreaterThan(0, $earned);
        $this->assertEquals(1, (int) $customer->total_purchases);

        // As if 7 points had been spent on this sale.
        $sale->update(['loyalty_points_redeemed' => 7]);

        $this->void($ctx, $sale)->assertOk();

        $customer->refresh();
        $this->assertSame(7, (int) $customer->loyalty_points);
        $this->assertEquals(0, (float) $customer->lifetime_spent);
        $this->assertEquals(0, (int) $customer->total_purchases);
    }

    public function test_a_cashier_needs_a_managers_pin_from_their_own_organization(): void
    {
        $ctx = $this->seedContext();
        $other = $this->seedContext('Other Org');

        $cashierRole = Role::query()->firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $cashier = User::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'is_super_user' => false,
            'location_id' => $ctx['store']->id,
        ]);
        $cashier->assignRole($cashierRole);

        // The other organization's admin uses a different PIN; it must not approve this void.
        UserPin::query()->where('user_id', $other['user']->id)->update(['pin_code' => Hash::make('9999')]);

        $sale = $this->paidSale($ctx);
        $sale->update(['user_id' => $cashier->id]);

        $this->void($ctx, $sale, ['pin_code' => '9999'], $cashier)
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin_code');

        $this->void($ctx, $sale, [], $cashier)->assertOk();
        $this->assertSame($ctx['user']->id, (int) $sale->fresh()->void_approved_by);
    }

    public function test_adding_a_product_again_keeps_the_line_subtotal_right(): void
    {
        $ctx = $this->seedContext();
        $sale = Sale::query()->create([
            'user_id' => $ctx['user']->id,
            'location_id' => $ctx['store']->id,
            'domain' => $ctx['domain']->name_slug,
            'payment_status' => 'pending',
            'invoice_number' => Str::upper(Str::random(10)),
            'transaction_date' => now(),
        ]);

        $service = app(ProductModifierService::class);
        $service->addToSale($sale, $ctx['product'], 1);
        $line = $service->addToSale($sale, $ctx['product'], 4);

        $this->assertEquals(5, (int) $line->fresh()->quantity);
        $this->assertEquals(500, (float) $line->fresh()->subtotal);
    }
}
