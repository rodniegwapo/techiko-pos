<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Product\Discount;

class SaleDiscount extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function mandatoryDiscount()
    {
        return $this->belongsTo(\App\Models\MandatoryDiscount::class, 'discount_id');
    }

    // Get the actual discount model based on type
    public function getDiscountAttribute()
    {
        if ($this->discount_type === 'mandatory') {
            return $this->mandatoryDiscount;
        }
        return $this->discount();
    }
}
