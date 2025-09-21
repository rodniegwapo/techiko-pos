<?php

namespace App\Models;

use App\Events\OrderUpdated;
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

        // discount_amount is already a column in sales
        $orderDiscountTotal = $this->saleDiscounts()->sum('discount_amount');
        $this->discount_amount = $orderDiscountTotal;

        $tax = $this->tax_amount ?? 0;

        $this->grand_total = max(0, $this->total_amount - $this->discount_amount + $tax);

        $this->save();

        // 🔹 Fire event here with fresh relationships
        event(new OrderUpdated($this->fresh([
            'saleItems',
            'saleDiscounts',
            'saleItems.discounts'
        ])));

    }

    public function applyOrderDiscount(Discount $discount): SaleDiscount
    {
        // validate
        throw_if($discount->scope !== 'order', \InvalidArgumentException::class, 'Discount scope must be order.');
        throw_if(! $discount->is_active, \InvalidArgumentException::class, 'Discount is not active.');
        throw_if($discount->start_date && now()->lt($discount->start_date), \InvalidArgumentException::class, 'Discount has not started yet.');
        throw_if($discount->end_date && now()->gt($discount->end_date), \InvalidArgumentException::class, 'Discount has expired.');
        throw_if($discount->min_order_amount && $this->total_amount < $discount->min_order_amount, \InvalidArgumentException::class, 'Order amount does not meet minimum requirement.');

        // calculate amount
        $amount = $discount->type === 'percentage'
            ? round(($discount->value / 100) * $this->total_amount, 2)
            : (float) $discount->value;

        $amount = min(max($amount, 0), $this->total_amount);

        // upsert record
        return $this->saleDiscounts()->updateOrCreate(
            ['discount_id' => $discount->id],
            ['discount_amount' => $amount]
        );
    }
}
