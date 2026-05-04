<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $domains = Domain::pluck('name_slug')->all();
        foreach ($domains as $slug) {
            // Get locations for this domain
            $locations = InventoryLocation::where('domain', $slug)->get();

            if ($locations->isEmpty()) {
                continue; // Skip if no locations exist
            }

            foreach ($locations as $location) {
                Product::factory()
                    ->count(9)
                    ->create(['domain' => $slug])
                    ->each(function (Product $product) use ($location) {
                        $product->locations()->attach($location->id, [
                            'is_active' => true,
                        ]);
                    });
            }
        }
    }
}
