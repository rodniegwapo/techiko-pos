<?php

namespace App\Models\Product;

use App\Models\SaleItem;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory,Searchable;

    protected $guarded = [];

    protected $searchable = ['name'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function saleItem()
    {
        return $this->belongsToMany(SaleItem::class);
    }

    // Remove domain relationship - now using domain string column
    // public function domain()
    // {
    //     return $this->belongsTo(\App\Models\Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain) {
        return $query->where('domain', $domain);
    }
}
