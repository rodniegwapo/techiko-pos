<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLocation extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected $searchable = [
        'name',
        'code',
        'type',
        'address',
        'contact_person',
        'phone',
        'email',
    ];

    /**
     * Get the product inventory records for this location
     */
    public function productInventories()
    {
        return $this->hasMany(ProductInventory::class, 'location_id');
    }

    /**
     * Get the inventory movements for this location
     */
    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class, 'location_id');
    }

    /**
     * Get the stock adjustments for this location
     */
    public function stockAdjustments()
    {
        return $this->hasMany(StockAdjustment::class, 'location_id');
    }

    /**
     * Scope to get only active locations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the default location
     */
    public static function getDefault()
    {
        return static::where('is_default', true)->first() 
            ?? static::active()->first();
    }

    /**
     * Get total inventory value for this location
     */
    public function getTotalInventoryValue()
    {
        return $this->productInventories()
            ->sum('total_value');
    }

    /**
     * Get low stock products count for this location
     */
    public function getLowStockProductsCount()
    {
        return $this->productInventories()
            ->whereHas('product', function ($query) {
                $query->where('track_inventory', true);
            })
            ->whereRaw('quantity_available <= (SELECT reorder_level FROM products WHERE products.id = product_inventory.product_id)')
            ->count();
    }
}