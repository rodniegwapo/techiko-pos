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
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:3',
        'quantity_reserved' => 'decimal:3',
        'quantity_available' => 'decimal:3',
        'average_cost' => 'decimal:4',
        'last_cost' => 'decimal:4',
        'total_value' => 'decimal:4',
        'last_movement_at' => 'datetime',
        'last_restock_at' => 'datetime',
        'last_sale_at' => 'datetime',
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
     * Check if product is low stock
     */
    public function isLowStock()
    {
        return $this->quantity_available <= $this->product->reorder_level;
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
}