<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /** Number of SKU rows seeded for each inventory location (distinct catalog per store). */
    private const PRODUCTS_PER_LOCATION = 3;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $domains = Domain::pluck('name_slug')->all();
        foreach ($domains as $slug) {
            $locations = InventoryLocation::where('domain', $slug)->get();

            if ($locations->isEmpty()) {
                continue;
            }

            $skuBase = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($slug)), 24, ''));

            if ($skuBase === '') {
                $skuBase = 'PRD';
            }

            $productIndex = 0;

            foreach ($locations as $location) {
                for ($k = 1; $k <= self::PRODUCTS_PER_LOCATION; $k++) {
                    $productIndex++;

                    $product = Product::factory()->create([
                        'domain' => $slug,
                        'category_id' => Category::query()
                            ->where('domain', $slug)
                            ->inRandomOrder()
                            ->value('id'),
                        'SKU' => $this->skuForIndex($skuBase, $productIndex),
                        'barcode' => $this->seededBarcode($slug, $productIndex),
                    ]);

                    $product->locations()->syncWithoutDetaching([
                        $location->id => ['is_active' => true],
                    ]);
                }
            }
        }
    }

    private function skuForIndex(string $skuBase, int $index): string
    {
        return Str::limit("{$skuBase}-{$index}", 255, '');
    }

    private function seededBarcode(string $slug, int $index): string
    {
        $hash = crc32($slug.'|'.$index);

        return sprintf('%013d', ($hash & 0x7FFFFFFF) % 10_000_000_000_000);
    }
}
