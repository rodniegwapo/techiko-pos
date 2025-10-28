<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class LocationProduct extends Pivot
{
    protected $table = 'location_product';
    
    protected $fillable = [
        'location_id',
        'product_id',
        'is_active'
    ];
    
    protected $casts = [
        'is_active' => 'boolean'
    ];
    
    /**
     * Get the location that owns this pivot record
     */
    public function location()
    {
        return $this->belongsTo(InventoryLocation::class);
    }
    
    /**
     * Get the product that owns this pivot record
     */
    public function product()
    {
        return $this->belongsTo(Product\Product::class);
    }
}
