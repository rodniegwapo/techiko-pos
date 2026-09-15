<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright categories suite (tests/e2e/categories). Runs after E2EProductSeeder.
 *
 * Categories are organization-wide, so every worker gets uniquely named ones: a plain category,
 * one that has a product (can't be deleted), and 16 "page" categories so a search spans two
 * pages of 15. One McDonald's category is used for cross-organization checks. Categories created
 * by tests ("E2E New Cat …") are deleted. Runs only in local/testing.
 */
class E2ECategorySeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2ECategorySeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            // Products must go first: products.category_id has no cascade.
            Product::where('SKU', 'like', 'E2E-CAT-%')->delete();
            Category::where(fn ($q) => $q->where('name', 'like', 'E2E Cat %')->orWhere('name', 'like', 'E2E New Cat %'))->delete();

            $workers = [];
            for ($n = 1; $n <= E2EWalletSeeder::WORKERS; $n++) {
                $plain = Category::create(['domain' => self::DOMAIN, 'name' => "E2E Cat W{$n} Alpha", 'description' => "Plain category for worker {$n}"]);
                $used = Category::create(['domain' => self::DOMAIN, 'name' => "E2E Cat W{$n} In Use", 'description' => 'Has a product']);

                $store = InventoryLocation::where('code', "E2E-WAL-{$n}")->firstOrFail();
                $product = Product::create([
                    'domain' => self::DOMAIN,
                    'name' => "E2E Cat Product W{$n}",
                    'SKU' => "E2E-CAT-W{$n}",
                    'barcode' => "E2E-CAT-BAR-W{$n}",
                    'sold_type' => 'Piece',
                    'price' => 1,
                    'category_id' => $used->id,
                    'track_inventory' => false,
                    'representation_type' => 'color',
                    'representation' => '94a3b8',
                ]);
                $product->addToLocation($store, true);

                for ($p = 1; $p <= 16; $p++) {
                    Category::create(['domain' => self::DOMAIN, 'name' => sprintf('E2E Cat W%d Page %02d', $n, $p), 'description' => 'Pagination']);
                }

                $workers[$n] = [
                    'plain' => ['id' => $plain->id, 'name' => $plain->name, 'description' => $plain->description],
                    'inUse' => ['id' => $used->id, 'name' => $used->name, 'productCount' => 1],
                    'pagePrefix' => "E2E Cat W{$n} Page",
                    'pageCount' => 16,
                ];
            }

            $other = Category::create(['domain' => 'mcdonalds-corp', 'name' => 'E2E Cat McDonalds', 'description' => 'Another organization']);

            return ['workers' => $workers, 'otherOrgCategoryId' => $other->id];
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all['categories'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
