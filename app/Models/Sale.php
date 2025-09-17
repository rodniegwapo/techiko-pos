<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon; 
use App\Models\Product\Discount;

class Sale extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function saleDiscounts()
    {
        return $this->hasMany(SaleDiscount::class);
    }

    public function recalcTotals(): void
    {
        $itemsTotal = $this->saleItems()
            ->whereNull('deleted_at')
            ->get()
            ->sum(function (SaleItem $item) {
                $lineSubtotal = $item->unit_price * $item->quantity;
                $lineDiscount = $item->discount ?? 0;
                return max(0, $lineSubtotal - $lineDiscount);
            });

        $this->total_amount = $itemsTotal;

        $orderDiscountTotal = $this->saleDiscounts()->sum('discount_amount');
        $this->discount_amount = $orderDiscountTotal;

        $tax = $this->tax_amount ?? 0;
        $this->grand_total = max(0, $this->total_amount - $this->discount_amount + $tax);

        $this->save();
    }

    public function applyOrderDiscount(Discount $discount): SaleDiscount
    {
        if ($discount->scope !== 'order') {
            throw new \InvalidArgumentException('Discount scope must be order.');
        }

        if (! $discount->is_active) {
            throw new \InvalidArgumentException('Discount is not active.');
        }

        if ($discount->start_date && now()->lt($discount->start_date)) {
            throw new \InvalidArgumentException('Discount is not started yet.');
        }

        if ($discount->end_date && now()->gt($discount->end_date)) {
            throw new \InvalidArgumentException('Discount has expired.');
        }

        $this->recalcTotals();

        if ($discount->min_order_amount && $this->total_amount < $discount->min_order_amount) {
            throw new \InvalidArgumentException('Order amount does not meet minimum requirement.');
        }

        $amount = 0;
        if ($discount->type === 'percentage') {
            $amount = round(($discount->value / 100) * $this->total_amount, 2);
        } else {
            $amount = (float) $discount->value;
        }

        $amount = min(max($amount, 0), $this->total_amount);

        $saleDiscount = $this->saleDiscounts()->create([
            'discount_id' => $discount->id,
            'discount_amount' => $amount,
        ]);

        $this->recalcTotals();

        return $saleDiscount;
    }

}
