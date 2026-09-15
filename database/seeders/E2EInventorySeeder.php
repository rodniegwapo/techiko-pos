<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
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

            return [
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
