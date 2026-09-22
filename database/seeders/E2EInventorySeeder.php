<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright inventory dashboard suite (tests/e2e/inventory). Runs after
 * E2EMandatoryDiscountSeeder.
 *
 * One inactive store (E2E-INV) holds four tracked products with known stock, so the dashboard's
 * counts, value, category chart and low-stock list have exact expected values. The store is
 * inactive so it doesn't show up in other suites' store pickers, and nothing else sells or moves
 * stock there. Rebuilt every run. Local/testing only.
 */
class E2EInventorySeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EInventorySeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            $oldStoreId = InventoryLocation::where('code', 'E2E-INV')->value('id');
            if ($oldStoreId) {
                // Movements keep a null product when their product is deleted, so remove them first.
                InventoryMovement::where('location_id', $oldStoreId)->delete();
            }
            Product::where('SKU', 'like', 'E2E-INV-%')->delete();

            $store = InventoryLocation::updateOrCreate(
                ['code' => 'E2E-INV'],
                [
                    'domain' => self::DOMAIN,
                    'name' => 'E2E Inventory Store',
                    'type' => 'store',
                    'address' => '1 E2E Street',
                    'is_active' => false,
                    'is_default' => false,
                    'notes' => 'Playwright inventory dashboard tests only',
                ]
            );
            ProductInventory::where('location_id', $store->id)->delete();

            $meals = Category::updateOrCreate(['name' => 'E2E Inv Meals', 'domain' => self::DOMAIN], ['description' => 'Playwright']);
            $drinks = Category::updateOrCreate(['name' => 'E2E Inv Drinks', 'domain' => self::DOMAIN], ['description' => 'Playwright']);

            // name, sku, category, quantity, cost, product reorder level, store reorder level
            $plenty = $this->product($store, 'E2E Inv Plenty', 'E2E-INV-PLENTY', $meals->id, 50, 10, 5, null);
            $low = $this->product($store, 'E2E Inv Low', 'E2E-INV-LOW', $meals->id, 3, 20, 5, null);
            $empty = $this->product($store, 'E2E Inv Empty', 'E2E-INV-EMPTY', $drinks->id, 0, 30, 5, null);
            // Plenty by the product's reorder level (2), low by this store's own level (10).
            $storeLow = $this->product($store, 'E2E Inv Store Low', 'E2E-INV-STORELOW', $drinks->id, 8, 15, 2, 10);

            $movements = $this->movements($store, $plenty, $low, $storeLow, $empty);

            return [
                'movements' => $movements,
                'store' => ['id' => $store->id, 'name' => $store->name, 'code' => $store->code, 'address' => $store->address],
                'products' => [
                    'plenty' => ['name' => $plenty->name, 'sku' => $plenty->SKU, 'qty' => 50],
                    'low' => ['name' => $low->name, 'sku' => $low->SKU, 'qty' => 3, 'min' => 5],
                    'empty' => ['name' => $empty->name, 'sku' => $empty->SKU, 'qty' => 0],
                    'storeLow' => ['name' => $storeLow->name, 'sku' => $storeLow->SKU, 'qty' => 8, 'min' => 10],
                ],
                'summary' => ['total' => 4, 'inStock' => 1, 'lowStock' => 2, 'outOfStock' => 1, 'value' => 680],
                'categories' => [
                    ['name' => 'E2E Inv Meals', 'in_stock' => 1, 'low_stock' => 1, 'out_of_stock' => 0],
                    ['name' => 'E2E Inv Drinks', 'in_stock' => 0, 'low_stock' => 1, 'out_of_stock' => 1],
                ],
                'mainLocation' => InventoryLocation::where('code', 'JB-MAIN')->first(['id', 'name', 'code'])->toArray(),
                'branchLocation' => InventoryLocation::where('code', 'JB-BRANCH')->first(['id', 'name', 'code'])->toArray(),
                'otherOrgLocationId' => InventoryLocation::where('code', 'MC-MAIN')->value('id'),
            ];
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all['inventoryDashboard'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Movement history at the E2E store (tests/e2e/inventory/movements.spec.js): five distinct
     * movements, newest first, plus 60 older one-piece sales so the 50-per-page list has a page 2.
     * Written directly, so they don't change the stock the dashboard tests expect.
     */
    private function movements(InventoryLocation $store, Product $plenty, Product $low, Product $storeLow, Product $empty): array
    {
        $adminId = User::where('email', 'admin@jollibee-corp.com')->value('id');
        $base = ['domain' => self::DOMAIN, 'location_id' => $store->id, 'user_id' => $adminId];

        $adjustment = InventoryMovement::create($base + [
            'product_id' => $storeLow->id, 'movement_type' => 'adjustment',
            'quantity_before' => 13, 'quantity_change' => -5, 'quantity_after' => 8,
            'reason' => 'E2E count correction', 'created_at' => '2026-03-12 08:45:00',
        ]);
        $sale = InventoryMovement::create($base + [
            'product_id' => $low->id, 'movement_type' => 'sale',
            'quantity_before' => 5, 'quantity_change' => -2, 'quantity_after' => 3,
            'reference_type' => 'Sale','reference_id' => 424242,
            'created_at' => '2026-03-11 02:15:00',
        ]);
        $purchase = InventoryMovement::create($base + [
            'product_id' => $plenty->id, 'movement_type' => 'purchase',
            'quantity_before' => 30, 'quantity_change' => 20, 'quantity_after' => 50,
            'unit_cost' => 10, 'total_cost' => 200,
            'batch_number' => 'E2E-BATCH-001', 'expiry_date' => '2027-06-30',
            'reason' => 'E2E restock', 'notes' => 'E2E delivered by supplier',
            'created_at' => '2026-03-10 06:30:00',
        ]);
        $transferIn = InventoryMovement::create($base + [
            'product_id' => $empty->id, 'movement_type' => 'transfer_in',
            'quantity_before' => 0, 'quantity_change' => 4, 'quantity_after' => 4,
            'created_at' => '2026-03-09 04:00:00',
        ]);
        $damage = InventoryMovement::create($base + [
            'product_id' => $plenty->id, 'movement_type' => 'damage',
            'quantity_before' => 31, 'quantity_change' => -1, 'quantity_after' => 30,
            'reason' => 'E2E dropped tray', 'created_at' => '2026-03-08 03:00:00',
        ]);

        for ($i = 0; $i < 60; $i++) {
            InventoryMovement::create($base + [
                'product_id' => $plenty->id, 'movement_type' => 'sale',
                'quantity_before' => 91 - $i, 'quantity_change' => -1, 'quantity_after' => 90 - $i,
                'created_at' => sprintf('2026-01-%02d 03:00:00', 1 + intdiv($i, 3)),
            ]);
        }

        $row = fn (InventoryMovement $m, Product $p) => ['id' => $m->id, 'product' => $p->name, 'sku' => $p->SKU];

        return [
            'total' => 65,
            'adjustment' => $row($adjustment, $storeLow) + ['change' => -5, 'reason' => 'E2E count correction'],
            'sale' => $row($sale, $low) + ['change' => -2, 'referenceId' => 424242],
            'purchase' => $row($purchase, $plenty) + [
                'change' => 20, 'before' => 30, 'after' => 50, 'batch' => 'E2E-BATCH-001',
                'reason' => 'E2E restock', 'notes' => 'E2E delivered by supplier', 'category' => 'E2E Inv Meals',
            ],
            'transferIn' => $row($transferIn, $empty) + ['change' => 4],
            'damage' => $row($damage, $plenty) + ['change' => -1, 'reason' => 'E2E dropped tray'],
        ];
    }

    private function product(InventoryLocation $store, string $name, string $sku, int $categoryId, int $qty, float $cost, int $reorderLevel, ?int $storeReorderLevel): Product
    {
        $product = Product::create([
            'domain' => self::DOMAIN,
            'name' => $name,
            'SKU' => $sku,
            'barcode' => $sku,
            'sold_type' => 'Piece',
            'price' => $cost * 2,
            'cost' => $cost,
            'category_id' => $categoryId,
            'track_inventory' => true,
            'reorder_level' => $reorderLevel,
            'representation_type' => 'color',
            'representation' => '94a3b8',
        ]);

        $product->addToLocation($store, true);

        ProductInventory::create([
            'product_id' => $product->id,
            'location_id' => $store->id,
            'quantity_on_hand' => $qty,
            'quantity_reserved' => 0,
            'quantity_available' => $qty,
            'location_reorder_level' => $storeReorderLevel,
            'average_cost' => $cost,
            'last_cost' => $cost,
            'total_value' => $qty * $cost,
        ]);

        return $product;
    }
}
