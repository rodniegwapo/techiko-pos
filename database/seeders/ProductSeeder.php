<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\Product\Product;
use App\Models\InventoryLocation;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
            
            // Create products for each location
            foreach ($locations as $location) {
                Product::factory()->count(4)->create([
                    'domain' => $slug,
                    'location_id' => $location->id,
                ]);
            }
        }
    }
}
