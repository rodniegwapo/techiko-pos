<?php

namespace App\Models\Product;

use App\Models\Category;
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

    protected static function newFactory()
    {
        return ProductFactory::new();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
