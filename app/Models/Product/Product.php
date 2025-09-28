<?php

namespace App\Models\Product;

use App\Models\Category;
use App\Models\SaleItem;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use App\Models\InventoryLocation;
use App\Traits\Searchable;
use Database\Factories\Product\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    protected $searchable = [
        'price',
        'cost',
        'name',
        'SKU',
        'category.name'
    ];

    protected $casts = [
        'track_inventory' => 'boolean',
        'reorder_level' => 'decimal:2',
        'max_stock_level' => 'decimal:2',
        'unit_weight' => 'decimal:3',
    ];

    protected static function newFactory()
    {
        return ProductFactory::new();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function saleitems()
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Get the inventory records for this product
     */
    public function inventories()
    {
        return $this->hasMany(ProductInventory::class);
    }

    /**
     * Get the inventory movements for this product
     */
    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Get inventory for a specific location
     */
    public function inventoryAt(InventoryLocation $location)
    {
        return $this->inventories()->where('location_id', $location->id)->first();
    }

    /**
     * Get inventory for the default location
     */
    public function defaultInventory()
    {
        $defaultLocation = InventoryLocation::getDefault();
        return $defaultLocation ? $this->inventoryAt($defaultLocation) : null;
    }

    /**
     * Get total quantity across all locations
     */
    public function getTotalQuantityAttribute()
    {
        return $this->inventories()->sum('quantity_on_hand');
    }

    /**
     * Get total available quantity across all locations
     */
    public function getTotalAvailableQuantityAttribute()
    {
        return $this->inventories()->sum('quantity_available');
    }

    /**
     * Check if product is in stock at any location
     */
    public function isInStock($quantity = 1, InventoryLocation $location = null)
    {
        if (!$this->track_inventory) {
            return true; // Non-tracked items are always "in stock"
        }

        if ($location) {
            $inventory = $this->inventoryAt($location);
            return $inventory ? $inventory->isInStock($quantity) : false;
        }

        return $this->getTotalAvailableQuantityAttribute() >= $quantity;
    }

    /**
     * Check if product is low stock
     */
    public function isLowStock(InventoryLocation $location = null)
    {
        if (!$this->track_inventory) {
            return false;
        }

        if ($location) {
            $inventory = $this->inventoryAt($location);
            return $inventory ? $inventory->isLowStock() : true;
        }

        return $this->getTotalAvailableQuantityAttribute() <= $this->reorder_level;
    }

    /**
     * Get stock status
     */
    public function getStockStatus(InventoryLocation $location = null)
    {
        if (!$this->track_inventory) {
            return 'in_stock';
        }

        if ($location) {
            $inventory = $this->inventoryAt($location);
            return $inventory ? $inventory->getStockStatus() : 'out_of_stock';
        }

        $totalAvailable = $this->getTotalAvailableQuantityAttribute();
        
        if ($totalAvailable <= 0) {
            return 'out_of_stock';
        }

        if ($totalAvailable <= $this->reorder_level) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /**
     * Scope for products that track inventory
     */
    public function scopeTracked($query)
    {
        return $query->where('track_inventory', true);
    }

    /**
     * Scope for products that are in stock
     */
    public function scopeInStock($query, InventoryLocation $location = null)
    {
        if ($location) {
            return $query->whereHas('inventories', function ($q) use ($location) {
                $q->where('location_id', $location->id)
                  ->where('quantity_available', '>', 0);
            });
        }

        return $query->whereHas('inventories', function ($q) {
            $q->where('quantity_available', '>', 0);
        });
    }

    /**
     * Scope for products that are low stock
     */
    public function scopeLowStock($query, InventoryLocation $location = null)
    {
        $query->where('track_inventory', true);

        if ($location) {
            return $query->whereHas('inventories', function ($q) use ($location) {
                $q->where('location_id', $location->id)
                  ->whereRaw('quantity_available <= products.reorder_level');
            });
        }

        return $query->whereRaw('(SELECT SUM(quantity_available) FROM product_inventory WHERE product_id = products.id) <= products.reorder_level');
    }

    /**
     * Scope for products that are out of stock
     */
    public function scopeOutOfStock($query, InventoryLocation $location = null)
    {
        $query->where('track_inventory', true);

        if ($location) {
            return $query->whereHas('inventories', function ($q) use ($location) {
                $q->where('location_id', $location->id)
                  ->where('quantity_available', '<=', 0);
            });
        }

        return $query->whereRaw('(SELECT SUM(quantity_available) FROM product_inventory WHERE product_id = products.id) <= 0');
    }
}
