<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
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

            $categories = Category::factory()
                ->count(2)
                ->create([
                    'domain' => $slug,
                ]);

            foreach ($categories as $category) {
                foreach ($locations as $location) {
                    DB::table('category_location')->updateOrInsert(
                        [
                            'category_id' => $category->id,
                            'location_id' => $location->id,
                        ],
                        [
                            'is_active' => true,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }
        }
    }
}
