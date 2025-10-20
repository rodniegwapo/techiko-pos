<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
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
            Category::factory()->count(5)->create([
                'domain' => $slug,
            ]);
        }
    }
}
