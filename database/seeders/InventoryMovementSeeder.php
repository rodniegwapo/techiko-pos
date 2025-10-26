<?php

namespace Database\Seeders;

use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class InventoryMovementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('📈 Creating historical sales movements...');
        $this->createSalesMovements();
        
        $this->command->info('📦 Creating purchase/receiving movements...');
        $this->createPurchaseMovements();
        
        $this->command->info('🔄 Creating transfer movements...');
        $this->createTransferMovements();
        
        $this->command->info('⚖️ Creating adjustment movements...');
        $this->createAdjustmentMovements();
        
        $this->command->info('🔄 Creating return movements...');
        $this->createReturnMovements();
        
        $this->command->info('✅ Inventory movements seeded successfully!');
    }

    /**
     * Create historical sales movements
     */
    private function createSalesMovements()
    {
        $products = Product::where('track_inventory', true)->get();
        $locations = InventoryLocation::where('is_active', true)->get();
        $users = User::all();
        
        if ($users->isEmpty()) {
            $this->command->error('No users found. Please run UserSeeder first.');
            return;
        }

        $movementCount = 0;
        $startDate = now()->subMonths(6);
        $endDate = now();

        foreach ($products as $product) {
            foreach ($locations as $location) {
                // Create 10-50 sales movements per product per location over 6 months
                $movementCountForProduct = rand(10, 50);
                
                for ($i = 0; $i < $movementCountForProduct; $i++) {
                    $movementDate = Carbon::createFromTimestamp(
                        rand($startDate->timestamp, $endDate->timestamp)
                    );
                    
                    $quantitySold = rand(1, 5);
                    $unitCost = $product->cost ?? rand(50, 500);
                    
                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'location_id' => $location->id,
                        'domain' => $location->domain,
                        'user_id' => $users->random()->id,
                        'movement_type' => 'sale',
                        'quantity_before' => rand(10, 100), // Will be updated by actual inventory
                        'quantity_change' => -$quantitySold, // Negative for sales
                        'quantity_after' => rand(5, 95), // Will be updated by actual inventory
                        'unit_cost' => $unitCost,
                        'total_cost' => $quantitySold * $unitCost,
                        'reference_type' => null,
                        'reference_id' => null,
                        'notes' => 'Sale transaction #' . rand(1000, 9999),
                        'reason' => 'Customer purchase',
                        'batch_number' => 'SALE-' . rand(10000, 99999),
                        'created_at' => $movementDate,
                        'updated_at' => $movementDate,
                    ]);
                    
                    $movementCount++;
                }
            }
        }

        $this->command->info("Created {$movementCount} sales movements");
    }

    /**
     * Create purchase/receiving movements
     */
    private function createPurchaseMovements()
    {
        $products = Product::where('track_inventory', true)->get();
        $locations = InventoryLocation::where('type', 'warehouse')->get();
        $users = User::all();
        
        if ($users->isEmpty()) {
            return;
        }

        $movementCount = 0;
        $startDate = now()->subMonths(3);
        $endDate = now();

        foreach ($products as $product) {
            foreach ($locations as $location) {
                // Create 2-8 purchase movements per product per warehouse over 3 months
                $movementCountForProduct = rand(2, 8);
                
                for ($i = 0; $i < $movementCountForProduct; $i++) {
                    $movementDate = Carbon::createFromTimestamp(
                        rand($startDate->timestamp, $endDate->timestamp)
                    );
                    
                    $quantityReceived = rand(20, 200);
                    $unitCost = $product->cost ?? rand(50, 500);
                    
                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'location_id' => $location->id,
                        'domain' => $location->domain,
                        'user_id' => $users->random()->id,
                        'movement_type' => 'purchase',
                        'quantity_before' => rand(50, 300),
                        'quantity_change' => $quantityReceived,
                        'quantity_after' => rand(70, 500),
                        'unit_cost' => $unitCost,
                        'total_cost' => $quantityReceived * $unitCost,
                        'reference_type' => null,
                        'reference_id' => null,
                        'notes' => 'Purchase order #PO-' . rand(1000, 9999),
                        'reason' => 'Stock replenishment',
                        'batch_number' => 'PURCHASE-' . rand(10000, 99999),
                        'expiry_date' => now()->addMonths(rand(6, 24)),
                        'created_at' => $movementDate,
                        'updated_at' => $movementDate,
                    ]);
                    
                    $movementCount++;
                }
            }
        }

        $this->command->info("Created {$movementCount} purchase movements");
    }

    /**
     * Create transfer movements
     */
    private function createTransferMovements()
    {
        $products = Product::where('track_inventory', true)->get();
        $warehouses = InventoryLocation::where('type', 'warehouse')->get();
        $stores = InventoryLocation::where('type', 'store')->get();
        $users = User::all();
        
        if ($users->isEmpty() || $warehouses->isEmpty() || $stores->isEmpty()) {
            return;
        }

        $movementCount = 0;
        $startDate = now()->subMonths(2);
        $endDate = now();

        foreach ($products as $product) {
            // Create 1-5 transfer movements per product over 2 months
            $movementCountForProduct = rand(1, 5);
            
            for ($i = 0; $i < $movementCountForProduct; $i++) {
                $movementDate = Carbon::createFromTimestamp(
                    rand($startDate->timestamp, $endDate->timestamp)
                );
                
                $fromLocation = $warehouses->random();
                $toLocation = $stores->random();
                $transferQuantity = rand(10, 50);
                
                // Transfer out from warehouse
                InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $fromLocation->id,
                    'domain' => $fromLocation->domain,
                    'user_id' => $users->random()->id,
                    'movement_type' => 'transfer_out',
                    'quantity_before' => rand(100, 500),
                    'quantity_change' => -$transferQuantity,
                    'quantity_after' => rand(50, 450),
                    'unit_cost' => $product->cost ?? rand(50, 500),
                    'total_cost' => $transferQuantity * ($product->cost ?? rand(50, 500)),
                    'reference_type' => null,
                    'reference_id' => null,
                    'notes' => "Transfer to {$toLocation->name}",
                    'reason' => 'Stock redistribution',
                    'batch_number' => 'TRANSFER-OUT-' . rand(10000, 99999),
                    'created_at' => $movementDate,
                    'updated_at' => $movementDate,
                ]);
                
                // Transfer in to store
                InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $toLocation->id,
                    'domain' => $toLocation->domain,
                    'user_id' => $users->random()->id,
                    'movement_type' => 'transfer_in',
                    'quantity_before' => rand(5, 50),
                    'quantity_change' => $transferQuantity,
                    'quantity_after' => rand(15, 100),
                    'unit_cost' => $product->cost ?? rand(50, 500),
                    'total_cost' => $transferQuantity * ($product->cost ?? rand(50, 500)),
                    'reference_type' => null,
                    'reference_id' => null,
                    'notes' => "Transfer from {$fromLocation->name}",
                    'reason' => 'Stock redistribution',
                    'batch_number' => 'TRANSFER-IN-' . rand(10000, 99999),
                    'created_at' => $movementDate,
                    'updated_at' => $movementDate,
                ]);
                
                $movementCount += 2;
            }
        }

        $this->command->info("Created {$movementCount} transfer movements");
    }

    /**
     * Create adjustment movements
     */
    private function createAdjustmentMovements()
    {
        $products = Product::where('track_inventory', true)->get();
        $locations = InventoryLocation::where('is_active', true)->get();
        $users = User::all();
        
        if ($users->isEmpty()) {
            return;
        }

        $movementCount = 0;
        $startDate = now()->subMonths(1);
        $endDate = now();

        foreach ($products as $product) {
            foreach ($locations as $location) {
                // Create 1-3 adjustment movements per product per location over 1 month
                $movementCountForProduct = rand(1, 3);
                
                for ($i = 0; $i < $movementCountForProduct; $i++) {
                    $movementDate = Carbon::createFromTimestamp(
                        rand($startDate->timestamp, $endDate->timestamp)
                    );
                    
                    $adjustmentReasons = [
                        'damage' => 'Damaged goods found during inspection',
                        'theft' => 'Inventory discrepancy - possible theft',
                        'expired' => 'Products past expiration date',
                        'miscount' => 'Physical count adjustment',
                        'promotion' => 'Promotional giveaway',
                    ];
                    
                    $reason = array_rand($adjustmentReasons);
                    $quantityChange = match ($reason) {
                        'damage', 'theft', 'expired' => -rand(1, 10),
                        'miscount' => rand(-5, 5),
                        'promotion' => -rand(1, 3),
                        default => rand(-2, 2)
                    };
                    
                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'location_id' => $location->id,
                        'domain' => $location->domain,
                        'user_id' => $users->random()->id,
                        'movement_type' => 'adjustment',
                        'quantity_before' => rand(10, 100),
                        'quantity_change' => $quantityChange,
                        'quantity_after' => rand(5, 105),
                        'unit_cost' => $product->cost ?? rand(50, 500),
                        'total_cost' => abs($quantityChange) * ($product->cost ?? rand(50, 500)),
                        'reference_type' => null,
                        'reference_id' => null,
                        'notes' => $adjustmentReasons[$reason],
                        'reason' => ucfirst($reason),
                        'batch_number' => 'ADJ-' . rand(10000, 99999),
                        'created_at' => $movementDate,
                        'updated_at' => $movementDate,
                    ]);
                    
                    $movementCount++;
                }
            }
        }

        $this->command->info("Created {$movementCount} adjustment movements");
    }

    /**
     * Create return movements
     */
    private function createReturnMovements()
    {
        $products = Product::where('track_inventory', true)->get();
        $locations = InventoryLocation::where('type', 'store')->get();
        $users = User::all();
        
        if ($users->isEmpty() || $locations->isEmpty()) {
            return;
        }

        $movementCount = 0;
        $startDate = now()->subMonths(1);
        $endDate = now();

        foreach ($products as $product) {
            foreach ($locations as $location) {
                // Create 0-2 return movements per product per store over 1 month
                $movementCountForProduct = rand(0, 2);
                
                for ($i = 0; $i < $movementCountForProduct; $i++) {
                    $movementDate = Carbon::createFromTimestamp(
                        rand($startDate->timestamp, $endDate->timestamp)
                    );
                    
                    $returnQuantity = rand(1, 3);
                    $unitCost = $product->cost ?? rand(50, 500);
                    
                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'location_id' => $location->id,
                        'domain' => $location->domain,
                        'user_id' => $users->random()->id,
                        'movement_type' => 'return',
                        'quantity_before' => rand(10, 50),
                        'quantity_change' => $returnQuantity,
                        'quantity_after' => rand(11, 53),
                        'unit_cost' => $unitCost,
                        'total_cost' => $returnQuantity * $unitCost,
                        'reference_type' => null,
                        'reference_id' => null,
                        'notes' => 'Customer return - ' . ['defective', 'wrong item', 'changed mind'][rand(0, 2)],
                        'reason' => 'Customer return',
                        'batch_number' => 'RETURN-' . rand(10000, 99999),
                        'created_at' => $movementDate,
                        'updated_at' => $movementDate,
                    ]);
                    
                    $movementCount++;
                }
            }
        }

        $this->command->info("Created {$movementCount} return movements");
    }
}
