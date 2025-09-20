<?php

namespace App\Models;

use App\Models\Product\Discount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
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

        // discount_amount is already a column in sales
        $discount = $this->discount_amount ?? 0;

        $tax = $this->tax_amount ?? 0;

        $this->grand_total = max(0, $this->total_amount - $discount + $tax);

        $this->save();
    }

    public function applyOrderDiscount(Discount $discount): void
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

        // calculate discount
        $amount = $discount->type === 'percentage'
            ? round(($discount->value / 100) * $this->total_amount, 2)
            : (float) $discount->value;

        $amount = min(max($amount, 0), $this->total_amount);

        // update sale record directly
        $this->discount_id = $discount->id;
        $this->discount_amount = $amount;
        $this->save();

        $this->recalcTotals();
    }
}
