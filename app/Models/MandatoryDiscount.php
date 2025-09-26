<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MandatoryDiscount extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'decimal:2',
    ];

    protected $searchableFields = ['name', 'type'];
}
