<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleItem extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];

    protected static function booted()
    {
        // Auto-calculate subtotal before saving
        static::saving(function (SaleItem $item) {
            $lineSubtotal = $item->unit_price * $item->quantity;
            $item->subtotal = $lineSubtotal - ($item->discount ?? 0);
        });
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product\Product::class);
    }

    public function discounts()
    {
        return $this->belongsToMany(\App\Models\Product\Discount::class);
    }

    public function setDiscountAmount(?string $type, ?float $discountAmount): void
    {
        $lineSubtotal = $this->unit_price * $this->quantity;

        if ($type === null || $discountAmount === null) {
            // 🔹 No discount (reset)
            $this->discount = 0;
            $this->subtotal = $lineSubtotal;
        } elseif ($type === 'amount') {
            // Apply discount per item * quantity
            $totalDiscount = round($discountAmount * $this->quantity, 2);
            $discount = min(max($totalDiscount, 0), $lineSubtotal);
            $this->discount = $discount;
            $this->subtotal = $lineSubtotal - $discount;
        } else {
            // Treat as percentage
            $percentage = max(min($discountAmount, 100), 0);
            $discount = round($lineSubtotal * ($percentage / 100), 2);
            $this->discount = $discount;
            $this->subtotal = $lineSubtotal - $discount;
        }

        $this->save();
    }
}
