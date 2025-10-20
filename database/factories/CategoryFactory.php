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

        $englishDescriptions = [
            'Electronics and gadgets',
            'Clothing and apparel',
            'Daily grocery items',
            'Home and living essentials',
            'Sports and outdoor gear',
            'Beauty and health products',
            'Office and school supplies',
            'Automotive parts and accessories',
            'Books and media',
            'Garden and outdoor tools',
        ];

        return [
            // Use clear English category names; allow duplicates across domains
            'name' => $this->faker->randomElement($categories),
            'description' => $this->faker->randomElement($englishDescriptions),
        ];
    }
}
