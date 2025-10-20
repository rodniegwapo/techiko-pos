<?php


namespace Database\Factories\Product;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Product\Product; // important

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{

    protected $model = Product::class;

    public function definition(): array
    {
        // Pool of more realistic product names
        $products = [
            'Smartphone',
            'Laptop',
            'Tablet',
            'Headphones',
            'Smartwatch',
            'Running Shoes',
            'T-Shirt',
            'Backpack',
            'LED TV',
            'Refrigerator',
            'Washing Machine',
            'Office Chair',
            'Book - Novel',
            'Board Game',
            'Soccer Ball',
            'Lipstick',
            'Perfume',
            'Car Battery',
            'Printer Ink',
            'Coffee Maker',
            'Garden Hose'
        ];

        $type = $this->faker->randomElement(['image', 'color', null]);

        return [
            // Use clear English names; allow duplicates across domains
            'name' => $this->faker->randomElement($products),
            'sold_type' => $this->faker->randomElement(['piece', 'box', 'pack']),
            'price' => $this->faker->numberBetween(100, 50000),
            'cost' => $this->faker->numberBetween(50, 40000),
            'SKU' => strtoupper(Str::random(10)),
            'barcode' => $this->faker->ean13(),
            'representation_type' => $type,
            'representation' => match ($type) {
                'image' => $this->faker->imageUrl(400, 400, 'product', true), // random product image
                'color' => ltrim($this->faker->hexColor(), '#'), // hex color without '#'
                default => null,
            },
            'category_id' => \App\Models\Category::inRandomOrder()->first()->id ?? 1,
            // ensures it uses a real category (fallback to 1 if none exist)
            
            // Inventory management fields
            'track_inventory' => true,
            'reorder_level' => $this->faker->numberBetween(3, 15), // Realistic reorder levels
            'max_stock_level' => $this->faker->numberBetween(50, 200), // Max stock levels
            'unit_weight' => $this->faker->randomFloat(3, 0.1, 10.0),
            'unit_of_measure' => $this->faker->randomElement(['piece', 'kg', 'liter', 'box', 'pack']),
        ];
    }
}
