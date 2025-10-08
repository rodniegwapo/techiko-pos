<?php

namespace App\Services;

use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    /**
     * Get or create inventory record for a product at a location
     */
    public function getOrCreateInventory(Product $product, InventoryLocation $location): ProductInventory
    {
        return ProductInventory::firstOrCreate(
            [
                'product_id' => $product->id,
                'location_id' => $location->id,
            ],
            [
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'quantity_available' => 0,
                'average_cost' => $product->cost ?? 0,
                'last_cost' => $product->cost ?? 0,
                'total_value' => 0,
            ]
        );
    }

    /**
     * Check if sufficient stock is available for a sale
     */
    public function checkStockAvailability(array $items, InventoryLocation $location = null): array
    {
        $location = $location ?? InventoryLocation::getDefault();
        $unavailableItems = [];

        foreach ($items as $item) {
            $product = Product::find($item['product_id']);
            
            if (!$product || !$product->track_inventory) {
                continue; // Skip non-tracked items
            }

            $inventory = $this->getOrCreateInventory($product, $location);
            $requestedQuantity = $item['quantity'] ?? 1;

            if (!$inventory->isInStock($requestedQuantity)) {
                $unavailableItems[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'requested_quantity' => $requestedQuantity,
                    'available_quantity' => $inventory->quantity_available,
                    'shortage' => $requestedQuantity - $inventory->quantity_available,
                ];
            }
        }

        return $unavailableItems;
    }

    /**
     * Reserve inventory for pending orders
     */
    public function reserveInventory(array $items, InventoryLocation $location = null): bool
    {
        $location = $location ?? InventoryLocation::getDefault();

        return DB::transaction(function () use ($items, $location) {
            foreach ($items as $item) {
                $product = Product::find($item['product_id']);
                
                if (!$product || !$product->track_inventory) {
                    continue;
                }

                $inventory = $this->getOrCreateInventory($product, $location);
                $quantity = $item['quantity'] ?? 1;

                try {
                    $inventory->reserveQuantity($quantity);
                } catch (\Exception $e) {
                    Log::error('Failed to reserve inventory', [
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'available' => $inventory->quantity_available,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }

            return true;
        });
    }

    /**
     * Release reserved inventory
     */
    public function releaseReservedInventory(array $items, InventoryLocation $location = null): bool
    {
        $location = $location ?? InventoryLocation::getDefault();

        return DB::transaction(function () use ($items, $location) {
            foreach ($items as $item) {
                $product = Product::find($item['product_id']);
                
                if (!$product || !$product->track_inventory) {
                    continue;
                }

                $inventory = $this->getOrCreateInventory($product, $location);
                $quantity = $item['quantity'] ?? 1;

                $inventory->releaseReservedQuantity($quantity);
            }

            return true;
        });
    }

    /**
     * Process inventory for completed sale
     */
    public function processSaleInventory(array $items, $saleId, User $user, InventoryLocation $location = null): bool
    {
        $location = $location ?? InventoryLocation::getDefault();

        return DB::transaction(function () use ($items, $saleId, $user, $location) {
            foreach ($items as $item) {
                $product = Product::find($item['product_id']);
                
                if (!$product || !$product->track_inventory) {
                    continue;
                }

                $quantity = $item['quantity'] ?? 1;
                $unitPrice = $item['unit_price'] ?? $product->price;

                // Record the sale movement
                $this->recordMovement([
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'movement_type' => 'sale',
                    'quantity_change' => -$quantity, // Negative for sale
                    'unit_cost' => $product->cost,
                    'reference_type' => 'Sale',
                    'reference_id' => $saleId,
                    'user_id' => $user->id,
                    'notes' => "Sale - Unit Price: {$unitPrice}",
                ]);
            }

            return true;
        });
    }

    /**
     * Record an inventory movement and update stock levels
     */
    public function recordMovement(array $data): InventoryMovement
    {
        return DB::transaction(function () use ($data) {
            $product = Product::findOrFail($data['product_id']);
            $location = InventoryLocation::findOrFail($data['location_id']);
            
            // Get or create inventory record
            $inventory = $this->getOrCreateInventory($product, $location);
            
            // Create the movement record
            $movement = InventoryMovement::createMovement($data);
            
            // Update inventory levels
            $this->updateInventoryLevels($inventory, $movement);
            
            // Update product stock status
            $this->updateProductStockStatus($product);
            
            Log::info('Inventory movement recorded', [
                'product_id' => $product->id,
                'location_id' => $location->id,
                'movement_type' => $movement->movement_type,
                'quantity_change' => $movement->quantity_change,
                'new_quantity' => $inventory->fresh()->quantity_on_hand,
            ]);
            
            return $movement;
        });
    }

    /**
     * Update inventory levels based on movement
     */
    protected function updateInventoryLevels(ProductInventory $inventory, InventoryMovement $movement): void
    {
        $oldQuantity = $inventory->quantity_on_hand;
        $newQuantity = $oldQuantity + $movement->quantity_change;
        
        // Update quantity
        $inventory->quantity_on_hand = max(0, $newQuantity);
        
        // Update costs for incoming inventory
        if ($movement->quantity_change > 0 && $movement->unit_cost) {
            $inventory->updateAverageCost($movement->quantity_change, $movement->unit_cost);
        }
        
        // Update timestamps
        $inventory->last_movement_at = now();
        
        if ($movement->movement_type === 'purchase') {
            $inventory->last_restock_at = now();
        } elseif ($movement->movement_type === 'sale') {
            $inventory->last_sale_at = now();
        }
        
        $inventory->save();
    }

    /**
     * Update product stock status based on current inventory
     */
    protected function updateProductStockStatus(Product $product): void
    {
        if (!$product->track_inventory) {
            $product->stock_status = 'in_stock';
        } else {
            $product->stock_status = $product->getStockStatus();
        }
        
        $product->save();
    }

    /**
     * Receive inventory from purchase/supplier
     */
    public function receiveInventory(array $items, User $user, InventoryLocation $location = null, string $referenceType = null, int $referenceId = null): bool
    {
        $location = $location ?? InventoryLocation::getDefault();

        return DB::transaction(function () use ($items, $user, $location, $referenceType, $referenceId) {
            foreach ($items as $item) {
                $product = Product::find($item['product_id']);
                
                if (!$product) {
                    continue;
                }

                $quantity = $item['quantity'] ?? 0;
                $unitCost = $item['unit_cost'] ?? $product->cost;

                if ($quantity <= 0) {
                    continue;
                }

                $this->recordMovement([
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'movement_type' => 'purchase',
                    'quantity_change' => $quantity,
                    'unit_cost' => $unitCost,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'batch_number' => $item['batch_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'user_id' => $user->id,
                    'notes' => $item['notes'] ?? 'Inventory received',
                ]);
            }

            return true;
        });
    }

    /**
     * Create stock adjustment
     */
    public function createStockAdjustment(array $data, array $items, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($data, $items, $user) {
            $adjustment = StockAdjustment::create(array_merge($data, [
                'adjustment_number' => StockAdjustment::generateAdjustmentNumber(),
                'created_by' => $user->id,
                'status' => 'draft',
            ]));

            foreach ($items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $location = InventoryLocation::findOrFail($data['location_id']);
                
                // Get current system quantity
                $inventory = $this->getOrCreateInventory($product, $location);
                $systemQuantity = $inventory->quantity_on_hand;
                
                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $product->id,
                    'system_quantity' => $systemQuantity,
                    'actual_quantity' => $itemData['actual_quantity'],
                    'unit_cost' => $itemData['unit_cost'] ?? $product->cost,
                    'batch_number' => $itemData['batch_number'] ?? null,
                    'expiry_date' => $itemData['expiry_date'] ?? null,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            // Calculate total value change
            $adjustment->calculateTotalValueChange();

            return $adjustment;
        });
    }

    /**
     * Get low stock products
     */
    public function getLowStockProducts(InventoryLocation $location = null): \Illuminate\Database\Eloquent\Collection
    {
        $products = Product::tracked()
            ->lowStock($location)
            ->with(['inventories' => function ($query) use ($location) {
                if ($location) {
                    $query->where('location_id', $location->id);
                }
            }])
            ->get();

        // Transform the collection to include formatted data
        $products->transform(function ($product) use ($location) {
            $inventory = $product->inventories->where('location_id', $location->id ?? null)->first();
            $product->current_stock = $inventory ? $inventory->quantity_available : 0;
            $product->min_stock_level = $inventory ? $inventory->getEffectiveReorderLevel() : $product->reorder_level;
            return $product;
        });

        return $products;
    }

    /**
     * Get inventory report data
     */
    public function getInventoryReport(InventoryLocation $location = null): array
    {
        $location = $location ?? InventoryLocation::getDefault();

        $totalProducts = Product::tracked()->count();
        $inStockProducts = Product::inStock($location)->count();
        $lowStockProducts = Product::lowStock($location)->count();
        $outOfStockProducts = Product::outOfStock($location)->count();
        $totalInventoryValue = $location->getTotalInventoryValue();

        // Get category stock data
        $categoryStockData = $this->getCategoryStockData($location);

        return [
            'location' => $location,
            'summary' => [
                'total_products' => $totalProducts,
                'in_stock_products' => $inStockProducts,
                'low_stock_products' => $lowStockProducts,
                'out_of_stock_products' => $outOfStockProducts,
                'total_inventory_value' => $totalInventoryValue,
            ],
            'low_stock_products' => $this->getLowStockProducts($location),
            'category_stock_data' => $categoryStockData,
        ];
    }

    /**
     * Get category stock data for chart
     */
    public function getCategoryStockData(InventoryLocation $location = null): array
    {
        $query = Product::tracked()
            ->with(['category', 'inventories' => function ($q) use ($location) {
                if ($location) {
                    $q->where('location_id', $location->id);
                }
            }]);

        $products = $query->get();

        // Group by category and calculate stock levels
        $categoryData = $products->groupBy('category.name')->map(function ($categoryProducts) use ($location) {
            $inStock = 0;
            $lowStock = 0;
            $outOfStock = 0;

            foreach ($categoryProducts as $product) {
                $stockStatus = $product->getStockStatus($location);
                
                switch ($stockStatus) {
                    case 'in_stock':
                        $inStock++;
                        break;
                    case 'low_stock':
                        $lowStock++;
                        break;
                    case 'out_of_stock':
                        $outOfStock++;
                        break;
                }
            }

            return [
                'name' => $categoryProducts->first()->category->name ?? 'Uncategorized',
                'in_stock' => $inStock,
                'low_stock' => $lowStock,
                'out_of_stock' => $outOfStock,
            ];
        })->values()->toArray();

        return $categoryData;
    }

    /**
     * Transfer inventory between locations
     */
    public function transferInventory(Product $product, InventoryLocation $fromLocation, InventoryLocation $toLocation, float $quantity, User $user, string $notes = null): bool
    {
        return DB::transaction(function () use ($product, $fromLocation, $toLocation, $quantity, $user, $notes) {
            // Check if source has enough stock
            $fromInventory = $this->getOrCreateInventory($product, $fromLocation);
            
            if (!$fromInventory->isInStock($quantity)) {
                throw new \Exception("Insufficient stock at source location. Available: {$fromInventory->quantity_available}");
            }

            // Record transfer out
            $this->recordMovement([
                'product_id' => $product->id,
                'location_id' => $fromLocation->id,
                'movement_type' => 'transfer_out',
                'quantity_change' => -$quantity,
                'unit_cost' => $fromInventory->average_cost,
                'user_id' => $user->id,
                'notes' => $notes ?? "Transfer to {$toLocation->name}",
            ]);

            // Record transfer in
            $this->recordMovement([
                'product_id' => $product->id,
                'location_id' => $toLocation->id,
                'movement_type' => 'transfer_in',
                'quantity_change' => $quantity,
                'unit_cost' => $fromInventory->average_cost,
                'user_id' => $user->id,
                'notes' => $notes ?? "Transfer from {$fromLocation->name}",
            ]);

            return true;
        });
    }
}
