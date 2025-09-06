<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true), // e.g. "Super Phone"
            'sold_type' => $this->faker->randomElement(['piece', 'box', 'pack']),
            'price' => $this->faker->numberBetween(100, 50000),
            'cost' => $this->faker->numberBetween(50, 40000),
            'SKU' => strtoupper(Str::random(10)),
            'barcode' => $this->faker->ean13(),
            'representation_type' => $type = $this->faker->randomElement(['image', 'color', null]),
            'representation' => match ($type) {
                'image' => $this->faker->imageUrl(400, 400, 'product', true), // random product image
                'color' => ltrim($this->faker->hexColor(), '#'), // hex color without '#'
                default => null,
            },
            'category_id' => $this->faker->numberBetween(1, 5), // assumes categories seeded
        ];
    }
}
