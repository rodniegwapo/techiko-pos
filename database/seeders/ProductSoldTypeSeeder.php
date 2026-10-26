<?php

namespace Database\Seeders;

use App\Models\Product\ProductSoldType;
use Illuminate\Database\Seeder;

class ProductSoldTypeSeeder extends Seeder
{
    /**
     * How a product is sold: by the piece (whole numbers) or by weight or volume (Kilo, Liter),
     * which may be sold in parts such as 1.5 kg.
     */
    public function run(): void
    {
        $types = [
            ['name' => 'Piece', 'unit' => 'pc', 'allows_decimal' => false],
            ['name' => 'Box', 'unit' => 'box', 'allows_decimal' => false],
            ['name' => 'Pack', 'unit' => 'pack', 'allows_decimal' => false],
            ['name' => 'Dozen', 'unit' => 'doz', 'allows_decimal' => false],
            ['name' => 'Kilo', 'unit' => 'kg', 'allows_decimal' => true],
            ['name' => 'Liter', 'unit' => 'L', 'allows_decimal' => true],
        ];

        foreach ($types as $type) {
            ProductSoldType::query()->updateOrCreate(['name' => $type['name']], $type);
        }
    }
}
