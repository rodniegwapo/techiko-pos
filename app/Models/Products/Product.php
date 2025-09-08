<?php

namespace App\Models\Products;

use App\Traits\Searchable;
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

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
