<?php

namespace App\Models;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventory extends Model
{
    use HasFactory;

    protected $table = 'product_inventory';

    protected $fillable = [
        'product_id',
        'location_id',
        'quantity_on_hand',
        'quantity_reserved',
        'quantity_available',
        'average_cost',
        'last_cost',
        'total_value',
        'last_movement_at',
        'last_restock_at',
        'last_sale_at',
        'location_reorder_level',
        'location_max_stock',
        'location_markup_percentage',
        'auto_reorder_enabled',
        'demand_pattern',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'quantity_reserved' => 'integer',
        'quantity_available' => 'integer',
        'average_cost' => 'decimal:4',
        'last_cost' => 'decimal:4',
        'total_value' => 'decimal:4',
        'last_movement_at' => 'datetime',
        'last_restock_at' => 'datetime',
        'last_sale_at' => 'datetime',
        'location_reorder_level' => 'integer',
        'location_max_stock' => 'integer',
        'location_markup_percentage' => 'decimal:2',
        'auto_reorder_enabled' => 'boolean',
        'demand_pattern' => 'array',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-calculate available quantity
        static::saving(function (ProductInventory $inventory) {
            $inventory->quantity_available = $inventory->quantity_on_hand - $inventory->quantity_reserved;
            $inventory->total_value = $inventory->quantity_on_hand * $inventory->average_cost;
        });
    }

    /**
     * Get the product that owns this inventory
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the location that owns this inventory
     */
    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    /**
     * Get inventory movements for this product at this location
     */
    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'product_id', 'product_id')
            ->where('location_id', $this->location_id);
    }

    /**
     * Check if product is in stock
     */
    public function isInStock($quantity = 1)
    {
        return $this->quantity_available >= $quantity;
    }

    /**
     * Check if product is low stock (location-specific or global)
     */
    public function isLowStock()
    {
        $reorderLevel = $this->location_reorder_level ?? $this->product->reorder_level;
        return $this->quantity_available <= $reorderLevel;
    }

    /**
     * Get stock status
     */
    public function getStockStatus()
    {
        if ($this->quantity_available <= 0) {
            return 'out_of_stock';
        }

        if ($this->isLowStock()) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /**
     * Reserve quantity for pending orders
     */
    public function reserveQuantity($quantity)
    {
        if ($this->quantity_available < $quantity) {
            throw new \Exception("Insufficient stock to reserve. Available: {$this->quantity_available}, Requested: {$quantity}");
        }

        $this->increment('quantity_reserved', $quantity);
        return $this;
    }

    /**
     * Release reserved quantity
     */
    public function releaseReservedQuantity($quantity)
    {
        $releaseAmount = min($quantity, $this->quantity_reserved);
        $this->decrement('quantity_reserved', $releaseAmount);
        return $this;
    }

    /**
     * Update average cost using weighted average method
     */
    public function updateAverageCost($newQuantity, $newCost)
    {
        if ($newQuantity <= 0) {
            return $this;
        }

        $currentValue = $this->quantity_on_hand * $this->average_cost;
        $newValue = $newQuantity * $newCost;
        $totalQuantity = $this->quantity_on_hand + $newQuantity;

        if ($totalQuantity > 0) {
            $this->average_cost = ($currentValue + $newValue) / $totalQuantity;
        }

        $this->last_cost = $newCost;
        return $this;
    }

    /**
     * Scope for products that are in stock
     */
    public function scopeInStock($query)
    {
        return $query->where('quantity_available', '>', 0);
    }

    /**
     * Scope for products that are low stock
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw('quantity_available <= (SELECT reorder_level FROM products WHERE products.id = product_inventory.product_id)');
    }

    /**
     * Scope for products that are out of stock
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('quantity_available', '<=', 0);
    }

    /**
     * Scope for products with auto-reorder enabled
     */
    public function scopeAutoReorderEnabled($query)
    {
        return $query->where('auto_reorder_enabled', true);
    }

    /**
     * Get effective reorder level (location-specific or global)
     */
    public function getEffectiveReorderLevel()
    {
        return $this->location_reorder_level ?? $this->product->reorder_level ?? 0;
    }

    /**
     * Get effective max stock level (location-specific or global)
     */
    public function getEffectiveMaxStock()
    {
        return $this->location_max_stock ?? $this->product->max_stock_level ?? null;
    }

    /**
     * Calculate demand velocity (units per day)
     */
    public function calculateDemandVelocity($days = 30)
    {
        $movements = InventoryMovement::where('product_id', $this->product_id)
            ->where('location_id', $this->location_id)
            ->where('movement_type', 'sale')
            ->where('created_at', '>=', now()->subDays($days))
            ->sum('quantity_change');

        return abs($movements) / $days; // Convert to positive and average per day
    }

    /**
     * Calculate days of stock remaining
     */
    public function calculateDaysOfStockRemaining()
    {
        $velocity = $this->calculateDemandVelocity();
        
        if ($velocity <= 0) {
            return 999; // Infinite if no sales
        }

        return ceil($this->quantity_available / $velocity);
    }

    /**
     * Update demand pattern with recent sales data
     */
    public function updateDemandPattern()
    {
        $pattern = [
            'velocity_7_days' => $this->calculateDemandVelocity(7),
            'velocity_30_days' => $this->calculateDemandVelocity(30),
            'velocity_90_days' => $this->calculateDemandVelocity(90),
            'days_of_stock' => $this->calculateDaysOfStockRemaining(),
            'last_updated' => now()->toISOString(),
        ];

        $this->update(['demand_pattern' => $pattern]);
        return $pattern;
    }

    /**
     * Check if needs reorder based on location-specific rules
     */
    public function needsReorder()
    {
        if (!$this->auto_reorder_enabled) {
            return false;
        }

        $reorderLevel = $this->getEffectiveReorderLevel();
        return $this->quantity_available <= $reorderLevel;
    }

    /**
     * Get recommended order quantity
     */
    public function getRecommendedOrderQuantity()
    {
        $maxStock = $this->getEffectiveMaxStock();
        
        if (!$maxStock) {
            // If no max stock defined, order enough for 30 days
            $velocity = $this->calculateDemandVelocity();
            return max(1, ceil($velocity * 30));
        }

        return max(1, $maxStock - $this->quantity_on_hand);
    }

    /**
     * Get location-specific price with markup
     */
    public function getLocationPrice()
    {
        $basePrice = $this->product->price;
        
        if ($this->location_markup_percentage) {
            return $basePrice * (1 + ($this->location_markup_percentage / 100));
        }

        return $basePrice;
    }
}