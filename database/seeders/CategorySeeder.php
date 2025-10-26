<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CategorySeeder extends Seeder
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
            
            // Create categories for each location
            foreach ($locations as $location) {
                Category::factory()->count(2)->create([
                    'domain' => $slug,
                    'location_id' => $location->id,
                ]);
            }
        }
    }
}
