<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\SharedProductSuggestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright products suite (tests/e2e/products). Runs after E2EWalletSeeder.
 *
 * Each worker's wallet store (E2E-WAL-n) gets its own catalog: three distinct products and 14
 * fillers (17 total, so the 15-per-page list has a second page). One "attach" product per worker
 * lives at JB-MAIN only, for the "Add existing to store" flow at JB-BRANCH; it is detached from
 * JB-BRANCH again every run. Products created by tests ("E2E New …") and their shared-catalog
 * suggestions are deleted. Runs only in local/testing.
 */
class E2EProductSeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EProductSeeder may only run in local or testing environments.');
        }

        $main = InventoryLocation::where('code', 'JB-MAIN')->firstOrFail();
        $branch = InventoryLocation::where('code', 'JB-BRANCH')->firstOrFail();

        $fixtures = DB::transaction(function () use ($main, $branch) {
            $this->cleanUp();

            $products = Category::updateOrCreate(['name' => 'E2E Products', 'domain' => self::DOMAIN], ['description' => 'Playwright']);
            $snacks = Category::updateOrCreate(['name' => 'E2E Snacks', 'domain' => self::DOMAIN], ['description' => 'Playwright']);

            $workers = [];
            for ($n = 1; $n <= E2EWalletSeeder::WORKERS; $n++) {
                $store = InventoryLocation::where('code', "E2E-WAL-{$n}")->firstOrFail();

                // Every product gets a barcode: products (domain, barcode) is unique and a missing
                // barcode is stored as '', so only one product per organization can lack one.
                $a = $this->product("E2E Product A W{$n}", "E2E-W{$n}-PA", 'Piece', 100, 60, $products->id, $store, 25, "E2E-W{$n}-BAR-A");
                $b = $this->product("E2E Product B W{$n}", "E2E-W{$n}-PB", 'Box', 250, 200, $snacks->id, $store, 8, "E2E-W{$n}-BAR-B");
                $c = $this->product("E2E Product C W{$n}", "E2E-W{$n}-PC", 'Pack', 45.5, null, null, $store, 0, "E2E-W{$n}-BAR-C");
                for ($f = 1; $f <= 14; $f++) {
                    $this->product(sprintf('E2E Filler W%d-%02d', $n, $f), sprintf('E2E-W%d-F%02d', $n, $f), 'Piece', 10, 5, $products->id, $store, 1, sprintf('E2E-W%d-BAR-F%02d', $n, $f));
                }

                $attach = $this->product("E2E Attach W{$n}", "E2E-W{$n}-ATT", 'Piece', 30, 20, $products->id, $main, 5, "E2E-W{$n}-BAR-ATT");
                $attach->activeLocations()->detach($branch->id);

                $workers[$n] = [
                    'storeId' => $store->id,
                    'storeName' => $store->name,
                    'total' => 17,
                    'a' => ['id' => $a->id, 'name' => $a->name, 'sku' => $a->SKU, 'barcode' => $a->barcode, 'price' => 100, 'cost' => 60, 'qty' => 25, 'category' => 'E2E Products', 'soldType' => 'Piece'],
                    'b' => ['id' => $b->id, 'name' => $b->name, 'sku' => $b->SKU, 'price' => 250, 'category' => 'E2E Snacks', 'soldType' => 'Box'],
                    'c' => ['id' => $c->id, 'name' => $c->name, 'sku' => $c->SKU, 'price' => 45.5, 'soldType' => 'Pack'],
                    'attach' => ['id' => $attach->id, 'name' => $attach->name, 'sku' => $attach->SKU],
                ];
            }

            return [
                'workers' => $workers,
                'categories' => ['products' => $products->id, 'snacks' => $snacks->id],
                'mainLocationId' => $main->id,
                'branchLocationId' => $branch->id,
                'otherOrg' => [
                    'categoryId' => Category::where('domain', 'mcdonalds-corp')->value('id'),
                    'locationId' => InventoryLocation::where('code', 'MC-MAIN')->value('id'),
                    'productId' => Product::where('domain', 'mcdonalds-corp')->value('id'),
                    'sku' => Product::where('domain', 'mcdonalds-corp')->whereNotNull('SKU')->value('SKU'),
                    'barcode' => Product::where('domain', 'mcdonalds-corp')->where('barcode', '!=', '')->value('barcode'),
                ],
            ];
        });

        $this->writeFixtureIds($fixtures);
    }

    /** Removes seeded worker catalogs and anything tests created, in any organization. */
    private function cleanUp(): void
    {
        $ids = Product::query()
            ->where(fn ($q) => $q->where('SKU', 'like', 'E2E-W%')
                ->orWhere('SKU', 'like', 'E2E-NEW-%')
                ->orWhere('name', 'like', 'E2E New %'))
            ->pluck('id');

        SharedProductSuggestion::query()
            ->where(fn ($q) => $q->whereIn('submitted_product_id', $ids)->orWhere('barcode', 'like', 'E2E-%'))
            ->delete();

        Product::whereIn('id', $ids)->delete();
    }

    private function product(string $name, string $sku, string $soldType, float $price, ?float $cost, ?int $categoryId, InventoryLocation $store, int $qty, string $barcode): Product
    {
        $product = Product::create([
            'domain' => self::DOMAIN,
            'name' => $name,
            'SKU' => $sku,
            'barcode' => $barcode,
            'sold_type' => $soldType,
            'price' => $price,
            'cost' => $cost,
            'category_id' => $categoryId,
            'track_inventory' => true,
            'reorder_level' => 0,
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
            'location_reorder_level' => 0,
            'average_cost' => $cost ?? 0,
            'last_cost' => $cost ?? 0,
            'total_value' => $qty * ($cost ?? 0),
        ]);

        return $product;
    }

    /** Adds a `catalog` section to tests/e2e/.fixtures.json (`products` belongs to E2ESalesSeeder). */
    private function writeFixtureIds(array $products): void
    {
        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $fixtures = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $fixtures['catalog'] = $products;
        file_put_contents($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
