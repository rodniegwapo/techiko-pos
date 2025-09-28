<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryTransferRecommendation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsService
{
    /**
     * Generate transfer recommendations based on stock levels and demand patterns
     */
    public function generateTransferRecommendations(): Collection
    {
        $recommendations = collect();

        // Get all active locations
        $locations = InventoryLocation::where('is_active', true)->get();

        // Find products that are out of stock or low stock at any location
        $lowStockProducts = ProductInventory::with(['product', 'location'])
            ->whereRaw('quantity_available <= COALESCE(location_reorder_level, (SELECT reorder_level FROM products WHERE products.id = product_inventory.product_id))')
            ->get();

        foreach ($lowStockProducts as $lowStockInventory) {
            // Find locations with excess stock of the same product
            $excessStockLocations = ProductInventory::where('product_id', $lowStockInventory->product_id)
                ->where('location_id', '!=', $lowStockInventory->location_id)
                ->where('quantity_available', '>', 0)
                ->whereRaw('quantity_available > COALESCE(location_reorder_level, (SELECT reorder_level FROM products WHERE products.id = product_inventory.product_id)) * 2')
                ->orderBy('quantity_available', 'desc')
                ->get();

            foreach ($excessStockLocations as $excessInventory) {
                $recommendation = $this->createTransferRecommendation(
                    $lowStockInventory,
                    $excessInventory
                );

                if ($recommendation) {
                    $recommendations->push($recommendation);
                }
            }
        }

        return $recommendations;
    }

    /**
     * Create a transfer recommendation between two locations
     */
    protected function createTransferRecommendation(
        ProductInventory $toInventory,
        ProductInventory $fromInventory
    ): ?InventoryTransferRecommendation {
        // Calculate recommended quantity
        $toReorderLevel = $toInventory->getEffectiveReorderLevel();
        $toMaxStock = $toInventory->getEffectiveMaxStock() ?? ($toReorderLevel * 3);
        $neededQuantity = $toMaxStock - $toInventory->quantity_available;
        
        // Don't transfer more than half of the source location's available stock
        $maxTransferQuantity = floor($fromInventory->quantity_available / 2);
        $recommendedQuantity = min($neededQuantity, $maxTransferQuantity);

        if ($recommendedQuantity <= 0) {
            return null;
        }

        // Calculate priority and other metrics
        $demandVelocity = $toInventory->calculateDemandVelocity();
        $daysOfStock = $toInventory->calculateDaysOfStockRemaining();
        $potentialLostSales = $demandVelocity * max(0, 7 - $daysOfStock) * $toInventory->product->price;

        $priority = $this->calculatePriority($toInventory, $daysOfStock, $potentialLostSales);
        $reason = $this->determineReason($toInventory, $fromInventory);

        // Check if similar recommendation already exists
        $existingRecommendation = InventoryTransferRecommendation::where('product_id', $toInventory->product_id)
            ->where('from_location_id', $fromInventory->location_id)
            ->where('to_location_id', $toInventory->location_id)
            ->where('status', 'pending')
            ->first();

        if ($existingRecommendation) {
            // Update existing recommendation
            $existingRecommendation->update([
                'recommended_quantity' => $recommendedQuantity,
                'priority' => $priority,
                'current_stock_from' => $fromInventory->quantity_available,
                'current_stock_to' => $toInventory->quantity_available,
                'demand_velocity_to' => $demandVelocity,
                'days_of_stock_remaining' => $daysOfStock,
                'potential_lost_sales' => $potentialLostSales,
                'expires_at' => now()->addDays(7),
            ]);

            return $existingRecommendation;
        }

        // Create new recommendation
        return InventoryTransferRecommendation::create([
            'product_id' => $toInventory->product_id,
            'from_location_id' => $fromInventory->location_id,
            'to_location_id' => $toInventory->location_id,
            'recommended_quantity' => $recommendedQuantity,
            'priority' => $priority,
            'reason' => $reason,
            'current_stock_from' => $fromInventory->quantity_available,
            'current_stock_to' => $toInventory->quantity_available,
            'demand_velocity_to' => $demandVelocity,
            'days_of_stock_remaining' => $daysOfStock,
            'potential_lost_sales' => $potentialLostSales,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Calculate priority based on stock situation
     */
    protected function calculatePriority(ProductInventory $inventory, int $daysOfStock, float $potentialLostSales): string
    {
        if ($inventory->quantity_available <= 0) {
            return 'urgent';
        }

        if ($daysOfStock <= 1 || $potentialLostSales > 1000) {
            return 'high';
        }

        if ($daysOfStock <= 3 || $potentialLostSales > 500) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Determine the reason for the transfer recommendation
     */
    protected function determineReason(ProductInventory $toInventory, ProductInventory $fromInventory): string
    {
        if ($toInventory->quantity_available <= 0) {
            return 'out_of_stock';
        }

        if ($toInventory->isLowStock()) {
            return 'low_stock';
        }

        $fromReorderLevel = $fromInventory->getEffectiveReorderLevel();
        if ($fromInventory->quantity_available > ($fromReorderLevel * 3)) {
            return 'excess_stock';
        }

        return 'demand_pattern';
    }

    /**
     * Get location performance analytics
     */
    public function getLocationAnalytics(InventoryLocation $location): array
    {
        $inventories = ProductInventory::where('location_id', $location->id)->get();

        $totalValue = $inventories->sum('total_value');
        $totalProducts = $inventories->count();
        $lowStockCount = $inventories->filter(fn($inv) => $inv->isLowStock())->count();
        $outOfStockCount = $inventories->filter(fn($inv) => $inv->quantity_available <= 0)->count();

        // Calculate turnover rate (last 30 days)
        $salesMovements = InventoryMovement::where('location_id', $location->id)
            ->where('movement_type', 'sale')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('quantity_change');

        $turnoverRate = $totalValue > 0 ? abs($salesMovements) / $totalValue : 0;

        // Get top selling products
        $topSellingProducts = InventoryMovement::where('location_id', $location->id)
            ->where('movement_type', 'sale')
            ->where('created_at', '>=', now()->subDays(30))
            ->select('product_id', DB::raw('SUM(ABS(quantity_change)) as total_sold'))
            ->groupBy('product_id')
            ->orderBy('total_sold', 'desc')
            ->limit(10)
            ->with('product')
            ->get();

        return [
            'total_inventory_value' => $totalValue,
            'total_products_count' => $totalProducts,
            'low_stock_products_count' => $lowStockCount,
            'out_of_stock_products_count' => $outOfStockCount,
            'turnover_rate' => $turnoverRate,
            'top_selling_products' => $topSellingProducts,
            'stock_health_percentage' => $totalProducts > 0 ? (($totalProducts - $outOfStockCount - $lowStockCount) / $totalProducts) * 100 : 100,
        ];
    }

    /**
     * Update location performance metrics
     */
    public function updateLocationMetrics(InventoryLocation $location): void
    {
        $analytics = $this->getLocationAnalytics($location);

        $location->update([
            'total_inventory_value' => $analytics['total_inventory_value'],
            'total_products_count' => $analytics['total_products_count'],
            'low_stock_products_count' => $analytics['low_stock_products_count'],
            'out_of_stock_products_count' => $analytics['out_of_stock_products_count'],
            'last_inventory_update' => now(),
            'performance_metrics' => [
                'turnover_rate' => $analytics['turnover_rate'],
                'stock_health_percentage' => $analytics['stock_health_percentage'],
                'top_selling_products' => $analytics['top_selling_products']->take(5)->toArray(),
                'last_calculated' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get products that need reordering
     */
    public function getReorderAlerts(InventoryLocation $location = null): Collection
    {
        $query = ProductInventory::with(['product', 'location'])
            ->where('auto_reorder_enabled', true);

        if ($location) {
            $query->where('location_id', $location->id);
        }

        return $query->get()->filter(function ($inventory) {
            return $inventory->needsReorder();
        });
    }

    /**
     * Generate comprehensive inventory report
     */
    public function generateInventoryReport(InventoryLocation $location = null): array
    {
        $locations = $location ? collect([$location]) : InventoryLocation::where('is_active', true)->get();
        
        $report = [
            'summary' => [
                'total_locations' => $locations->count(),
                'total_inventory_value' => 0,
                'total_products' => 0,
                'total_low_stock' => 0,
                'total_out_of_stock' => 0,
            ],
            'locations' => [],
            'transfer_recommendations' => [],
            'reorder_alerts' => [],
        ];

        foreach ($locations as $loc) {
            $analytics = $this->getLocationAnalytics($loc);
            $report['locations'][] = array_merge(['location' => $loc->toArray()], $analytics);
            
            $report['summary']['total_inventory_value'] += $analytics['total_inventory_value'];
            $report['summary']['total_products'] += $analytics['total_products_count'];
            $report['summary']['total_low_stock'] += $analytics['low_stock_products_count'];
            $report['summary']['total_out_of_stock'] += $analytics['out_of_stock_products_count'];
        }

        // Get pending transfer recommendations
        $recommendationsQuery = InventoryTransferRecommendation::with(['product', 'fromLocation', 'toLocation'])
            ->where('status', 'pending')
            ->orderBy('priority', 'desc')
            ->orderBy('potential_lost_sales', 'desc');

        if ($location) {
            $recommendationsQuery->where(function ($q) use ($location) {
                $q->where('from_location_id', $location->id)
                  ->orWhere('to_location_id', $location->id);
            });
        }

        $report['transfer_recommendations'] = $recommendationsQuery->limit(20)->get()->toArray();

        // Get reorder alerts
        $report['reorder_alerts'] = $this->getReorderAlerts($location)->toArray();

        return $report;
    }

    /**
     * Process automatic reorders for enabled products
     */
    public function processAutomaticReorders(): array
    {
        $reorderAlerts = $this->getReorderAlerts();
        $processed = [];

        foreach ($reorderAlerts as $inventory) {
            $recommendedQuantity = $inventory->getRecommendedOrderQuantity();
            
            // Here you would integrate with your purchasing system
            // For now, we'll just log the recommendation
            $processed[] = [
                'product' => $inventory->product->name,
                'location' => $inventory->location->name,
                'current_stock' => $inventory->quantity_available,
                'reorder_level' => $inventory->getEffectiveReorderLevel(),
                'recommended_quantity' => $recommendedQuantity,
                'estimated_cost' => $recommendedQuantity * $inventory->last_cost,
            ];
        }

        return $processed;
    }
}
