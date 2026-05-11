<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Products per catalog = 9 × (number of domain locations). Keeps seeded volume comparable
     * to the legacy per-location loop while sharing one SKU set across all stores in the domain.
     */
    private const PRODUCTS_PER_LOCATION_MULTIPLIER = 9;

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

            $catalogSize = max(1, $locations->count() * self::PRODUCTS_PER_LOCATION_MULTIPLIER);
            $skuBase = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($slug)), 24, ''));

            if ($skuBase === '') {
                $skuBase = 'PRD';
            }

            $pivotIds = $locations->mapWithKeys(fn (InventoryLocation $loc) => [
                $loc->id => ['is_active' => true],
            ])->all();

            for ($index = 1; $index <= $catalogSize; $index++) {
                $product = Product::factory()->create([
                    'domain' => $slug,
                    'SKU' => $this->skuForIndex($skuBase, $index),
                    'barcode' => $this->seededBarcode($slug, $index),
                ]);

                $product->locations()->syncWithoutDetaching($pivotIds);
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
