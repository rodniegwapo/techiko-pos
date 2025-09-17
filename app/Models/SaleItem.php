<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
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

    public function discounts()
    {
        return $this->belongsToMany(\App\Models\Product\Discount::class);
    }

    public function setDiscountAmount(string $type, float $discountAmount): void
    {
        $lineSubtotal = $this->unit_price * $this->quantity;

        if ($type === 'amount') {
            // Clamp between 0 and subtotal
            $discount = min(max($discountAmount, 0), $lineSubtotal);
        } else {
            // Treat as percentage
            $discount = $lineSubtotal * max(min($discountAmount, 100), 0) / 100;
        }

        $this->discount = $discount;
        $this->subtotal = $lineSubtotal - $discount;
        $this->save();
    }
}
