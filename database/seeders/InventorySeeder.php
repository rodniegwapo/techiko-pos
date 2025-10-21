<?php

namespace Database\Seeders;

use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Models\ProductInventory;
use App\Models\Domain;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🌍 Creating inventory locations...');
        $locations = $this->createInventoryLocations();
        
        $this->command->info('📦 Creating product inventory...');
        $this->createProductInventory($locations);
        
        $this->command->info('💰 Setting up location-specific pricing...');
        $this->setupLocationPricing($locations);
        
        $this->command->info('📊 Creating stock variety...');
        $this->createStockVariety($locations);
        
        $this->command->info('✅ Enhanced inventory seeded successfully!');
    }

    /**
     * Create diverse inventory locations
     */
    private function createInventoryLocations()
    {
        $locations = [];
        
        // Get domains to assign locations
        $domains = Domain::all();
        if ($domains->isEmpty()) {
            $this->command->warn('No domains found. Creating default domain...');
            $domain = Domain::create([
                'name' => 'Default Store',
                'name_slug' => 'default-store',
                'timezone' => 'Asia/Manila',
                'country_code' => 'PH',
                'currency_code' => 'PHP',
                'date_format' => 'Y-m-d',
                'time_format' => '12h',
                'language_code' => 'en',
                'is_active' => true,
            ]);
            $domains = collect([$domain]);
        }

        foreach ($domains as $domain) {
            // Main Store (Default)
            $mainStore = InventoryLocation::firstOrCreate(
                ['code' => 'MAIN'],
                [
                    'name' => 'Main Store - ' . $domain->name,
                    'code' => 'MAIN',
                    'type' => 'store',
                    'address' => '123 Main Street, ' . $domain->name,
                    'contact_person' => 'Store Manager',
                    'phone' => '+63-2-123-4567',
                    'email' => 'store@' . $domain->name_slug . '.com',
                    'is_active' => true,
                    'is_default' => true,
                    'domain' => $domain->name_slug,
                ]
            );
            $locations[] = $mainStore;

            // Warehouse
            $warehouse = InventoryLocation::firstOrCreate(
                ['code' => 'WH01'],
                [
                    'name' => 'Main Warehouse - ' . $domain->name,
                    'code' => 'WH01',
                    'type' => 'warehouse',
                    'address' => '456 Warehouse District, ' . $domain->name,
                    'contact_person' => 'Warehouse Manager',
                    'phone' => '+63-2-234-5678',
                    'email' => 'warehouse@' . $domain->name_slug . '.com',
                    'is_active' => true,
                    'is_default' => false,
                    'domain' => $domain->name_slug,
                ]
            );
            $locations[] = $warehouse;

            // Additional Store
            $store2 = InventoryLocation::firstOrCreate(
                ['code' => 'STORE2'],
                [
                    'name' => 'Branch Store - ' . $domain->name,
                    'code' => 'STORE2',
                    'type' => 'store',
                    'address' => '789 Mall Level 2, ' . $domain->name,
                    'contact_person' => 'Branch Manager',
                    'phone' => '+63-2-345-6789',
                    'email' => 'branch@' . $domain->name_slug . '.com',
                    'is_active' => true,
                    'is_default' => false,
                    'domain' => $domain->name_slug,
                ]
            );
            $locations[] = $store2;

            // Customer Location (for returns/exchanges)
            $customer = InventoryLocation::firstOrCreate(
                ['code' => 'CUST'],
                [
                    'name' => 'Customer Returns - ' . $domain->name,
                    'code' => 'CUST',
                    'type' => 'customer',
                    'address' => 'Customer Service Area',
                    'contact_person' => 'Customer Service',
                    'phone' => '+63-2-456-7890',
                    'email' => 'service@' . $domain->name_slug . '.com',
                    'is_active' => true,
                    'is_default' => false,
                    'domain' => $domain->name_slug,
                ]
            );
            $locations[] = $customer;
        }

        return $locations;
    }

    /**
     * Create realistic product inventory
     */
    private function createProductInventory($locations)
    {
        $products = Product::all();
        
        if ($products->isEmpty()) {
            $this->command->warn('No products found. Please run ProductSeeder first.');
            return;
        }

        foreach ($products as $product) {
            foreach ($locations as $location) {
                // Skip if inventory already exists
                if (ProductInventory::where('product_id', $product->id)
                    ->where('location_id', $location->id)
                    ->exists()) {
                    continue;
                }

                // Create realistic inventory based on location type
                $inventoryData = $this->generateInventoryData($product, $location);
                
                ProductInventory::create($inventoryData);
            }
        }

        $this->command->info("Created inventory for {$products->count()} products across " . count($locations) . " locations.");
    }

    /**
     * Generate realistic inventory data based on location type
     */
    private function generateInventoryData($product, $location)
    {
        $baseQuantity = match ($location->type) {
            'store' => rand(5, 50),
            'warehouse' => rand(50, 500),
            'customer' => rand(0, 10),
            'supplier' => rand(0, 5),
            default => rand(5, 50)
        };

        $cost = $product->cost ?? rand(50, 500);
        $quantityOnHand = $baseQuantity;
        $quantityReserved = rand(0, min(5, $quantityOnHand));
        $quantityAvailable = $quantityOnHand - $quantityReserved;

        return [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity_on_hand' => $quantityOnHand,
            'quantity_reserved' => $quantityReserved,
            'quantity_available' => $quantityAvailable,
            'average_cost' => $cost,
            'last_cost' => $cost,
            'total_value' => $quantityOnHand * $cost,
            'last_movement_at' => now()->subDays(rand(0, 30)),
            'last_restock_at' => now()->subDays(rand(1, 60)),
            'last_sale_at' => now()->subDays(rand(0, 7)),
            'location_reorder_level' => match ($location->type) {
                'store' => rand(5, 15),
                'warehouse' => rand(20, 50),
                'customer' => rand(0, 5),
                'supplier' => rand(0, 3),
                default => rand(5, 15)
            },
            'location_max_stock' => match ($location->type) {
                'store' => rand(50, 100),
                'warehouse' => rand(200, 1000),
                'customer' => rand(10, 30),
                'supplier' => rand(5, 20),
                default => rand(50, 100)
            },
            'location_markup_percentage' => match ($location->type) {
                'store' => rand(0, 10),
                'warehouse' => 0,
                'customer' => rand(0, 5),
                'supplier' => 0,
                default => 0
            },
            'auto_reorder_enabled' => rand(0, 1) == 1,
            'demand_pattern' => [
                'velocity_7_days' => rand(1, 10),
                'velocity_30_days' => rand(5, 50),
                'velocity_90_days' => rand(20, 200),
                'days_of_stock' => rand(7, 30),
                'last_updated' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Setup location-specific pricing
     */
    private function setupLocationPricing($locations)
    {
        foreach ($locations as $location) {
            $inventories = ProductInventory::where('location_id', $location->id)->get();
            
            foreach ($inventories as $inventory) {
                if ($inventory->location_markup_percentage > 0) {
                    $inventory->update([
                        'location_markup_percentage' => $inventory->location_markup_percentage
                    ]);
                }
            }
        }
    }

    /**
     * Create stock variety (in stock, low stock, out of stock)
     */
    private function createStockVariety($locations)
    {
        $inventories = ProductInventory::all();
        
        // Make some products out of stock
        $outOfStockCount = (int) ($inventories->count() * 0.1); // 10% out of stock
        $outOfStockInventories = $inventories->random($outOfStockCount);
        
        foreach ($outOfStockInventories as $inventory) {
            $inventory->update([
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'quantity_available' => 0,
                'last_sale_at' => now()->subDays(rand(1, 30)),
            ]);
        }

        // Make some products low stock
        $lowStockCount = (int) ($inventories->count() * 0.2); // 20% low stock
        $lowStockInventories = $inventories->where('quantity_available', '>', 0)->random($lowStockCount);
        
        foreach ($lowStockInventories as $inventory) {
            $lowQuantity = rand(1, $inventory->location_reorder_level ?? 5);
            $inventory->update([
                'quantity_on_hand' => $lowQuantity,
                'quantity_available' => $lowQuantity,
            ]);
        }

        $this->command->info("Created stock variety: {$outOfStockCount} out of stock, {$lowStockCount} low stock");
    }
}