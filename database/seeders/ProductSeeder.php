<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\Product\Product;
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
            Product::factory()->count(12)->create([
                'domain' => $slug,
            ]);
        }
    }
}
