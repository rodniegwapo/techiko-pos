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
}
