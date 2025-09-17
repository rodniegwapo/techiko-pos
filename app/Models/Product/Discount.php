<?php

namespace App\Models\Product;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory,Searchable;

    protected $guarded = [];

    protected $searchable = ['name'];

    public function saleItems()
    {
        return $this->belongsToMany(
            \App\Models\SaleItem::class,
            'sale_item_discounts',
            'discount_id',
            'sale_item_id'
        )->withTimestamps();
    }
}
