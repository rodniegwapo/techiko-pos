<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Custom product category names
        $categories = [
            'Electronics',
            'Clothing',
            'Groceries',
            'Furniture',
            'Books',
            'Toys',
            'Sports',
            'Beauty & Health',
            'Automotive',
            'Office Supplies',
            'Jewelry',
            'Home Appliances',
            'Shoes',
            'Garden',
        ];

        return [
            'name' => $this->faker->unique()->randomElement($categories),
            'description' => $this->faker->sentence(), // still generates English
        ];
    }
}
