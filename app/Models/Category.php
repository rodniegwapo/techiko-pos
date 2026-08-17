<?php

namespace App\Models;

use App\Models\Product\Product;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    protected $searchable = ['name', 'description'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Locations where this category is available (see category_location pivot).
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(
            InventoryLocation::class,
            'category_location',
            'category_id',
            'location_id'
        )
            ->withPivot('is_active')
            ->withTimestamps();
    }

    // Remove domain relationship - now using domain string column
    // public function domain(){
    //     return $this->belongsTo(Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain)
    {
        return $query->where('domain', $domain);
    }
}
