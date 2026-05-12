<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockEnforcementTest extends TestCase
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
     * @return array{domain: Domain, location: InventoryLocation, user: User, product: Product}
     */
    private function seedBasics(?array $salesSettings = null): array
    {
        $attributes = [
            'name' => 'Test Org',
            'name_slug' => 'test-org-'.Str::lower(Str::random(8)),
        ];
        if ($salesSettings !== null) {
            $attributes['settings'] = ['sales' => $salesSettings];
        }

        $domain = Domain::query()->create($attributes);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $location->id,
        ]);

        $category = Category::factory()->create([
            'domain' => $domain->name_slug,
        ]);

        $product = Product::factory()->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'track_inventory' => true,
            'price' => 100,
        ]);

        $product->activeLocations()->attach($location->id, ['is_active' => true]);

        return compact('domain', 'location', 'user', 'product');
    }

    private function inventoryFor(Product $product, InventoryLocation $location, int $onHand): void
    {
        ProductInventory::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0,
            'quantity_available' => $onHand,
        ]);
    }

    private function createPendingSale(User $user, Domain $domain, InventoryLocation $location, Product $product, int $qty): Sale
    {
        $sale = Sale::query()->create([
            'user_id' => $user->id,
            'location_id' => $location->id,
            'domain' => $domain->name_slug,
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
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => $product->price,
            'discount' => 0,
        ]);

        $sale->recalcTotals();

        return $sale;
    }

    public function test_strict_checkout_returns_422_when_insufficient_stock(): void
    {
        $ctx = $this->seedBasics(['allow_overselling' => false]);
        $this->inventoryFor($ctx['product'], $ctx['location'], 3);
        $sale = $this->createPendingSale($ctx['user'], $ctx['domain'], $ctx['location'], $ctx['product'], 10);

        $url = route('domains.sales.payment.store', [
            'domain' => $ctx['domain']->name_slug,
            'sale' => $sale->id,
        ]);

        $resp = $this->actingAs($ctx['user'])->postJson($url, ['payment_method' => 'cash']);
        $resp->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['stock']]);

        $this->assertEquals('pending', $sale->fresh()->payment_status);
    }

    public function test_permissive_checkout_succeeds_when_insufficient_stock(): void
    {
        $ctx = $this->seedBasics([]);
        $this->inventoryFor($ctx['product'], $ctx['location'], 2);
        $sale = $this->createPendingSale($ctx['user'], $ctx['domain'], $ctx['location'], $ctx['product'], 99);

        $url = route('domains.sales.payment.store', [
            'domain' => $ctx['domain']->name_slug,
            'sale' => $sale->id,
        ]);

        $resp = $this->actingAs($ctx['user'])->postJson($url, ['payment_method' => 'cash']);
        $resp->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEquals('paid', $sale->fresh()->payment_status);
    }

    public function test_strict_cart_add_returns_422_when_insufficient_stock(): void
    {
        $ctx = $this->seedBasics(['allow_overselling' => false]);
        $this->inventoryFor($ctx['product'], $ctx['location'], 4);
        $sale = $this->createPendingSale($ctx['user'], $ctx['domain'], $ctx['location'], $ctx['product'], 2);

        $url = route('domains.sales.cart.add', [
            'domain' => $ctx['domain']->name_slug,
            'sale' => $sale->id,
        ]);

        $this->actingAs($ctx['user'])->postJson($url, [
            'product_id' => $ctx['product']->id,
            'quantity' => 5,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('stock');

        $this->assertEquals(
            2,
            $sale->fresh()->saleItems()->where('product_id', $ctx['product']->id)->first()->quantity
        );
    }

    public function test_strict_second_checkout_fails_after_first_consumes_available_stock(): void
    {
        $ctx = $this->seedBasics(['allow_overselling' => false]);
        $this->inventoryFor($ctx['product'], $ctx['location'], 5);

        $saleOne = $this->createPendingSale($ctx['user'], $ctx['domain'], $ctx['location'], $ctx['product'], 5);
        $urlOne = route('domains.sales.payment.store', ['domain' => $ctx['domain']->name_slug, 'sale' => $saleOne->id]);
        $this->actingAs($ctx['user'])->postJson($urlOne, ['payment_method' => 'cash'])->assertOk();

        $saleTwo = $this->createPendingSale($ctx['user'], $ctx['domain'], $ctx['location'], $ctx['product'], 1);
        $urlTwo = route('domains.sales.payment.store', ['domain' => $ctx['domain']->name_slug, 'sale' => $saleTwo->id]);
        $this->actingAs($ctx['user'])->postJson($urlTwo, ['payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_offline_sync_strict_reports_failure_when_stock_insufficient(): void
    {
        $ctx = $this->seedBasics(['allow_overselling' => false]);
        $this->inventoryFor($ctx['product'], $ctx['location'], 1);

        $mutationId = (string) Str::uuid();
        $payload = [
            'items' => [
                [
                    'product_id' => $ctx['product']->id,
                    'quantity' => 5,
                    'unit_price' => 50,
                ],
            ],
            'payment_method' => 'cash',
            'location_id' => $ctx['location']->id,
            'cashier_user_id' => $ctx['user']->id,
            'customer_id' => null,
            'notes' => null,
            'recorded_at' => now()->toIso8601String(),
        ];

        $url = route('domains.sales.offline-sync', ['domain' => $ctx['domain']->name_slug]);
        $resp = $this->actingAs($ctx['user'])->postJson($url, [
            'sales' => [['client_mutation_id' => $mutationId, 'payload' => $payload]],
        ]);

        $resp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.client_mutation_id', $mutationId);
    }

    public function test_offline_sync_permissive_succeeds_when_stock_insufficient(): void
    {
        $ctx = $this->seedBasics([]);
        $this->inventoryFor($ctx['product'], $ctx['location'], 1);

        $mutationId = (string) Str::uuid();
        $payload = [
            'items' => [
                [
                    'product_id' => $ctx['product']->id,
                    'quantity' => 5,
                    'unit_price' => 50,
                ],
            ],
            'payment_method' => 'cash',
            'location_id' => $ctx['location']->id,
            'cashier_user_id' => $ctx['user']->id,
            'customer_id' => null,
            'notes' => null,
            'recorded_at' => now()->toIso8601String(),
        ];

        $url = route('domains.sales.offline-sync', ['domain' => $ctx['domain']->name_slug]);
        $resp = $this->actingAs($ctx['user'])->postJson($url, [
            'sales' => [['client_mutation_id' => $mutationId, 'payload' => $payload]],
        ]);

        $resp->assertOk()
            ->assertJsonPath('results.0.success', true);

        $this->assertNotNull($resp->json('results.0.sale_id'));
    }
}
