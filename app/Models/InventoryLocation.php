<?php

namespace App\Models;

use App\Traits\Searchable;
use App\Models\LocationProduct;
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
     * Get the domain that this location belongs to.
     * Remove domain relationship - now using domain string column
     */
    // public function domain()
    // {
    //     return $this->belongsTo(Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain) {
        return $query->where('domain', $domain);
    }

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
     * Get the sales for this location
     */
    public function sales()
    {
        return $this->hasMany(Sale::class, 'location_id');
    }

    /**
     * Get all products available at this location
     */
    public function products()
    {
        return $this->belongsToMany(Product\Product::class, 'location_product', 'location_id', 'product_id')
                    ->using(LocationProduct::class)
                    ->withPivot('is_active')
                    ->withTimestamps();
    }

    /**
     * Get only active products available at this location
     */
    public function activeProducts()
    {
        return $this->belongsToMany(Product\Product::class, 'location_product', 'location_id', 'product_id')
                    ->using(LocationProduct::class)
                    ->wherePivot('is_active', true)
                    ->withPivot('is_active')
                    ->withTimestamps();
    }

    /**
     * Scope to get only active locations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the default location for a specific domain
     */
    public static function getDefault($domain = null)
    {
        $query = static::where('is_default', true);
        
        if ($domain) {
            $domainSlug = $domain instanceof \App\Models\Domain ? $domain->name_slug : $domain;
            $query->where('domain', $domainSlug);
        }
        
        return $query->first() ?? static::active()->where('domain', $domainSlug ?? null)->first();
    }
    
    /**
     * Get the default location for a specific domain (scope)
     */
    public function scopeDefaultForDomain($query, $domain)
    {
        $domainSlug = $domain instanceof \App\Models\Domain ? $domain->name_slug : $domain;
        return $query->where('domain', $domainSlug)->where('is_default', true);
    }
    
    /**
     * Set as default location for its domain
     */
    public function setAsDefault()
    {
        // First, unset any existing default for this domain
        static::where('domain', $this->domain)
            ->where('is_default', true)
            ->update(['is_default' => false]);
        
        // Set this location as default
        $this->update(['is_default' => true]);
        
        return $this;
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