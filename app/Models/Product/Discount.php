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

    public function saleItem()
    {
        return $this->belongsToMany(SaleItem::class);
    }
}
