<?php

namespace App\Models;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Searchable;

class Category extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    protected $searchable = ['name', 'description'];

    public function products(){
        return $this->hasMany(Product::class);
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    // Remove domain relationship - now using domain string column
    // public function domain(){
    //     return $this->belongsTo(Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain) {
        return $query->where('domain', $domain);
    }
}
