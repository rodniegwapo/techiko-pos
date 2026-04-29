<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Product\ProductSoldType;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** Stays one below billing free-tier cap (default 10). */
        $countPerDomain = max(0, config('billing.free_tier_product_limit', 10) - 1);

        $soldTypeName = ProductSoldType::query()->orderBy('id')->value('name');
        if (! $soldTypeName) {
            $this->command?->error('No product sold types. Run ProductSoldTypeSeeder first.');

            return;
        }

        $domains = Domain::pluck('name_slug')->all();
        foreach ($domains as $slug) {
            $location = InventoryLocation::query()
                ->where('domain', $slug)
                ->where('type', 'store')
                ->where('is_default', true)
                ->first()
                ?? InventoryLocation::query()->where('domain', $slug)->where('type', 'store')->first()
                ?? InventoryLocation::query()->where('domain', $slug)->first();

            if (! $location) {
                $this->command?->warn("Skipping products for {$slug}: no inventory location.");

                continue;
            }

            $category = Category::query()->where('domain', $slug)->orderBy('id')->first();
            if (! $category) {
                $this->command?->warn("Skipping products for {$slug}: no category.");

                continue;
            }

            $products = Product::factory()
                ->count($countPerDomain)
                ->create([
                    'domain' => $slug,
                    'category_id' => $category->id,
                    'sold_type' => $soldTypeName,
                ]);

            foreach ($products as $product) {
                $product->addToLocation($location, true);
            }
        }
    }
}
