<?php

namespace Database\Seeders;

use App\Models\Product\ProductSoldType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSoldTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $types = [
            ['name' => 'Biece', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Box', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pack', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Dozen', 'created_at' => now(), 'updated_at' => now()],
        ];

        ProductSoldType::insert($types);
    }
}
