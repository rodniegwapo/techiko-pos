<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Searchable;

class Category extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    protected $searchable = ['name', 'description'];
}
