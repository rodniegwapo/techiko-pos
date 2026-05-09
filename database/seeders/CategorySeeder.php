<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use Illuminate\Database\Seeder;

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

            foreach ($locations as $location) {
                Category::factory()
                    ->count(2)
                    ->create(['domain' => $slug])
                    ->each(function (Category $category) use ($location) {
                        $category->locations()->attach($location->id, [
                            'is_active' => true,
                        ]);
                    });
            }
        }
    }
}
