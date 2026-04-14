<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\OfflineSaleSync;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSaleSyncTest extends TestCase
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
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Test Org',
            'name_slug' => 'test-org-'.Str::lower(Str::random(6)),
        ]);

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

        ProductInventory::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity_on_hand' => 100,
            'quantity_reserved' => 0,
            'quantity_available' => 100,
        ]);

        return compact('domain', 'location', 'user', 'product');
    }

    private function syncPayload(\App\Models\InventoryLocation $location, User $cashier, Product $product): array
    {
        return [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 50,
                ],
            ],
            'payment_method' => 'cash',
            'location_id' => $location->id,
            'cashier_user_id' => $cashier->id,
            'customer_id' => null,
            'notes' => null,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    public function test_offline_sync_is_idempotent_per_client_mutation_id(): void
    {
        $ctx = $this->seedContext();
        $mutationId = (string) Str::uuid();
        $payload = $this->syncPayload($ctx['location'], $ctx['user'], $ctx['product']);
        $body = [
            'sales' => [
                [
                    'client_mutation_id' => $mutationId,
                    'payload' => $payload,
                ],
            ],
        ];

        $url = route('domains.sales.offline-sync', ['domain' => $ctx['domain']->name_slug]);

        $first = $this->actingAs($ctx['user'])->postJson($url, $body);
        $first->assertOk()
            ->assertJsonPath('results.0.success', true);

        $saleId = $first->json('results.0.sale_id');
        $this->assertNotNull($saleId);

        $second = $this->actingAs($ctx['user'])->postJson($url, $body);
        $second->assertOk()
            ->assertJsonPath('results.0.success', true)
            ->assertJsonPath('results.0.duplicate', true)
            ->assertJsonPath('results.0.sale_id', $saleId);

        $this->assertSame(1, Sale::query()->forDomain($ctx['domain']->name_slug)->count());
        $this->assertSame(1, OfflineSaleSync::query()->where('client_mutation_id', $mutationId)->count());
    }
}
