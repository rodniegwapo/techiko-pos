<?php

namespace Database\Seeders;

use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Models\ProductInventory;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the default location (should already exist from migration)
        $defaultLocation = InventoryLocation::where('is_default', true)->first();
        
        if (!$defaultLocation) {
            $defaultLocation = InventoryLocation::create([
                'name' => 'Main Store',
                'code' => 'MAIN',
                'type' => 'store',
                'is_active' => true,
                'is_default' => true,
            ]);
        }

        // Create additional locations
        $warehouse = InventoryLocation::create([
            'name' => 'Main Warehouse',
            'code' => 'WH01',
            'type' => 'warehouse',
            'address' => '123 Warehouse District, Storage City',
            'contact_person' => 'John Warehouse',
            'phone' => '+1-555-0123',
            'is_active' => true,
            'is_default' => false,
        ]);

        // Get all products and create inventory records
        $products = Product::all();
        
        foreach ($products as $product) {
            // Create inventory for main store
            ProductInventory::create([
                'product_id' => $product->id,
                'location_id' => $defaultLocation->id,
                'quantity_on_hand' => rand(10, 100),
                'quantity_reserved' => 0,
                'quantity_available' => rand(10, 100),
                'average_cost' => $product->cost ?? rand(50, 500),
                'last_cost' => $product->cost ?? rand(50, 500),
                'total_value' => 0, // Will be calculated automatically
                'last_movement_at' => now(),
                'last_restock_at' => now()->subDays(rand(1, 30)),
            ]);

            // Create inventory for warehouse (higher quantities)
            ProductInventory::create([
                'product_id' => $product->id,
                'location_id' => $warehouse->id,
                'quantity_on_hand' => rand(50, 500),
                'quantity_reserved' => 0,
                'quantity_available' => rand(50, 500),
                'average_cost' => $product->cost ?? rand(50, 500),
                'last_cost' => $product->cost ?? rand(50, 500),
                'total_value' => 0, // Will be calculated automatically
                'last_movement_at' => now(),
                'last_restock_at' => now()->subDays(rand(1, 15)),
            ]);
        }

        $this->command->info('Inventory seeded successfully!');
        $this->command->info("Created inventory for {$products->count()} products across 2 locations.");
    }
}