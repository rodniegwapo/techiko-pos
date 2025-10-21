<?php

namespace Database\Seeders;

use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Models\ProductInventory;
use App\Models\InventoryTransferRecommendation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class InventoryTransferRecommendationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🤖 Creating AI transfer recommendations...');
        $this->createTransferRecommendations();
        
        $this->command->info('📊 Analyzing demand patterns...');
        $this->updateDemandPatterns();
        
        $this->command->info('✅ Transfer recommendations seeded successfully!');
    }

    /**
     * Create transfer recommendations
     */
    private function createTransferRecommendations()
    {
        $products = Product::where('track_inventory', true)->get();
        $warehouses = InventoryLocation::where('type', 'warehouse')->get();
        $stores = InventoryLocation::where('type', 'store')->get();
        $users = User::all();
        
        if ($users->isEmpty() || $warehouses->isEmpty() || $stores->isEmpty()) {
            $this->command->warn('Insufficient data for transfer recommendations.');
            return;
        }

        $recommendationCount = 0;
        $startDate = now()->subDays(30);
        $endDate = now()->addDays(30);

        foreach ($products as $product) {
            foreach ($stores as $store) {
                // Create 0-3 recommendations per product per store
                $recommendationsPerProduct = rand(0, 3);
                
                for ($i = 0; $i < $recommendationsPerProduct; $i++) {
                    $warehouse = $warehouses->random();
                    
                    // Check if there's actual inventory data
                    $warehouseInventory = ProductInventory::where('product_id', $product->id)
                        ->where('location_id', $warehouse->id)
                        ->first();
                    
                    $storeInventory = ProductInventory::where('product_id', $product->id)
                        ->where('location_id', $store->id)
                        ->first();
                    
                    if (!$warehouseInventory || !$storeInventory) {
                        continue;
                    }
                    
                    $recommendedQuantity = $this->calculateRecommendedQuantity($product, $store, $warehouse);
                    
                    if ($recommendedQuantity <= 0) {
                        continue;
                    }
                    
                    $priority = $this->calculatePriority($recommendedQuantity, $storeInventory);
                    $daysOfStock = $this->calculateDaysOfStock($storeInventory);
                    $demandVelocity = $this->calculateDemandVelocity($product, $store);
                    $potentialLostSales = $this->calculatePotentialLostSales($recommendedQuantity, $demandVelocity);
                    
                    $recommendationDate = Carbon::createFromTimestamp(
                        rand($startDate->timestamp, $endDate->timestamp)
                    );
                    
                    $expiresAt = $recommendationDate->copy()->addDays(rand(3, 14));
                    $approvedAt = rand(0, 1) ? $recommendationDate->copy()->addHours(rand(1, 72)) : null;
                    $processedAt = $approvedAt ? $approvedAt->copy()->addHours(rand(1, 48)) : null;
                    
                    InventoryTransferRecommendation::create([
                        'product_id' => $product->id,
                        'from_location_id' => $warehouse->id,
                        'to_location_id' => $store->id,
                        'recommended_quantity' => $recommendedQuantity,
                        'current_stock_from' => $warehouseInventory->quantity_available,
                        'current_stock_to' => $storeInventory->quantity_available,
                        'days_of_stock_remaining' => $daysOfStock,
                        'demand_velocity_to' => $demandVelocity,
                        'potential_lost_sales' => $potentialLostSales,
                        'priority' => $priority,
                        'reason' => $this->getRandomReason($priority),
                        'status' => $this->getRandomStatus($approvedAt, $processedAt),
                        'recommended_at' => $recommendationDate,
                        'expires_at' => $expiresAt,
                        'approved_at' => $approvedAt,
                        'approved_by' => $approvedAt ? $users->random()->id : null,
                        'processed_at' => $processedAt,
                        'processed_by' => $processedAt ? $users->random()->id : null,
                        'notes' => $this->generateRecommendationNotes($priority, $recommendedQuantity),
                        'created_at' => $recommendationDate,
                        'updated_at' => $recommendationDate,
                    ]);
                    
                    $recommendationCount++;
                }
            }
        }

        $this->command->info("Created {$recommendationCount} transfer recommendations");
    }

    /**
     * Calculate recommended transfer quantity
     */
    private function calculateRecommendedQuantity($product, $store, $warehouse)
    {
        $storeInventory = ProductInventory::where('product_id', $product->id)
            ->where('location_id', $store->id)
            ->first();
        
        $warehouseInventory = ProductInventory::where('product_id', $product->id)
            ->where('location_id', $warehouse->id)
            ->first();
        
        if (!$storeInventory || !$warehouseInventory) {
            return 0;
        }
        
        $reorderLevel = $storeInventory->location_reorder_level ?? $product->reorder_level ?? 10;
        $maxStock = $storeInventory->location_max_stock ?? $product->max_stock_level ?? 100;
        
        $currentStock = $storeInventory->quantity_available;
        $availableAtWarehouse = $warehouseInventory->quantity_available;
        
        // Calculate how much we need to reach optimal stock level
        $needed = max(0, $reorderLevel - $currentStock);
        $optimal = min($maxStock - $currentStock, $availableAtWarehouse);
        
        return min($needed, $optimal);
    }

    /**
     * Calculate priority based on quantity and current stock
     */
    private function calculatePriority($recommendedQuantity, $storeInventory)
    {
        $currentStock = $storeInventory->quantity_available;
        $reorderLevel = $storeInventory->location_reorder_level ?? 10;
        
        if ($currentStock <= 0) {
            return 'urgent';
        } elseif ($currentStock <= $reorderLevel * 0.5) {
            return 'high';
        } elseif ($currentStock <= $reorderLevel) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Calculate days of stock remaining
     */
    private function calculateDaysOfStock($storeInventory)
    {
        $currentStock = $storeInventory->quantity_available;
        $demandVelocity = $this->calculateDemandVelocityFromInventory($storeInventory);
        
        if ($demandVelocity <= 0) {
            return 999; // Infinite if no demand
        }
        
        return ceil($currentStock / $demandVelocity);
    }

    /**
     * Calculate demand velocity for a product at a location
     */
    private function calculateDemandVelocity($product, $location)
    {
        // Simulate demand velocity based on product and location
        $baseVelocity = match ($location->type) {
            'store' => rand(1, 5),
            'kiosk' => rand(0, 2),
            'online' => rand(2, 8),
            default => rand(1, 3)
        };
        
        // Add some randomness based on product type
        $productMultiplier = match ($product->sold_type ?? 'piece') {
            'piece' => 1.0,
            'kg' => 0.5,
            'liter' => 0.8,
            default => 1.0
        };
        
        return $baseVelocity * $productMultiplier;
    }

    /**
     * Calculate demand velocity from inventory data
     */
    private function calculateDemandVelocityFromInventory($inventory)
    {
        // Use the demand pattern if available, otherwise simulate
        if ($inventory->demand_pattern && isset($inventory->demand_pattern['velocity_30_days'])) {
            return $inventory->demand_pattern['velocity_30_days'];
        }
        
        return rand(1, 5); // Fallback simulation
    }

    /**
     * Calculate potential lost sales
     */
    private function calculatePotentialLostSales($recommendedQuantity, $demandVelocity)
    {
        if ($demandVelocity <= 0) {
            return 0;
        }
        
        // Estimate potential lost sales based on demand velocity and recommended quantity
        $daysWithoutStock = $recommendedQuantity / $demandVelocity;
        return $demandVelocity * $daysWithoutStock * rand(50, 500); // Random unit price
    }

    /**
     * Get random status based on dates
     */
    private function getRandomStatus($approvedAt, $processedAt)
    {
        if ($processedAt) {
            return 'completed';
        } elseif ($approvedAt) {
            return 'approved';
        } else {
            return 'pending';
        }
    }

    /**
     * Get random reason based on priority
     */
    private function getRandomReason($priority)
    {
        $reasons = [
            'urgent' => ['out_of_stock', 'low_stock'],
            'high' => ['low_stock', 'demand_pattern'],
            'medium' => ['demand_pattern', 'excess_stock'],
            'low' => ['excess_stock', 'manual_request'],
        ];

        $availableReasons = $reasons[$priority] ?? ['demand_pattern'];
        return $availableReasons[array_rand($availableReasons)];
    }

    /**
     * Generate recommendation notes
     */
    private function generateRecommendationNotes($priority, $quantity)
    {
        $notes = [
            'urgent' => [
                "URGENT: Store is out of stock. Transfer {$quantity} units immediately to prevent lost sales.",
                "CRITICAL: Zero inventory detected. Immediate transfer of {$quantity} units required.",
                "EMERGENCY: No stock available. Transfer {$quantity} units to meet customer demand.",
            ],
            'high' => [
                "HIGH PRIORITY: Low stock level. Transfer {$quantity} units to maintain service levels.",
                "IMPORTANT: Stock running low. Recommended transfer of {$quantity} units.",
                "PRIORITY: Below reorder level. Transfer {$quantity} units to prevent stockout.",
            ],
            'medium' => [
                "MEDIUM PRIORITY: Approaching reorder level. Transfer {$quantity} units for optimal stock.",
                "RECOMMENDED: Stock level declining. Transfer {$quantity} units to maintain buffer.",
                "SUGGESTED: Proactive transfer of {$quantity} units to prevent future stockout.",
            ],
            'low' => [
                "LOW PRIORITY: Optional transfer of {$quantity} units for stock optimization.",
                "OPTIONAL: Minor stock adjustment. Transfer {$quantity} units if capacity allows.",
                "SUGGESTION: Consider transferring {$quantity} units for better distribution.",
            ],
        ];

        return $notes[$priority][array_rand($notes[$priority])];
    }

    /**
     * Update demand patterns for products
     */
    private function updateDemandPatterns()
    {
        $inventories = ProductInventory::all();
        $updatedCount = 0;

        foreach ($inventories as $inventory) {
            $pattern = [
                'velocity_7_days' => rand(1, 10),
                'velocity_30_days' => rand(5, 50),
                'velocity_90_days' => rand(20, 200),
                'days_of_stock' => rand(7, 30),
                'last_updated' => now()->toISOString(),
            ];

            $inventory->update(['demand_pattern' => $pattern]);
            $updatedCount++;
        }

        $this->command->info("Updated demand patterns for {$updatedCount} inventory records");
    }
}
