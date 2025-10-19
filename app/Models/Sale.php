<?php

namespace App\Models;

use App\Events\OrderUpdated;
use App\Events\CustomerUpdated;
use App\Models\Product\Discount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $guarded = [];

    // Remove domain relationship - now using domain string column
    // public function domain()
    // {
    //     return $this->belongsTo(Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain) {
        return $query->where('domain', $domain);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function saleDiscounts()
    {
        return $this->hasMany(SaleDiscount::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
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
            'saleItems.product',
            'saleDiscounts',
            'saleItems.discounts',
            'customer'
        ])));

    }

    /**
     * Update customer and trigger customer update event
     */
    public function updateCustomer(?int $customerId): void
    {
        \Log::info("Sale::updateCustomer called", [
            'sale_id' => $this->id,
            'customer_id' => $customerId
        ]);
        
        $this->update(['customer_id' => $customerId]);
        
        \Log::info("CustomerUpdated event being fired", [
            'sale_id' => $this->id,
            'customer_id' => $customerId
        ]);
        
        // Trigger customer update event to notify frontend
        event(new CustomerUpdated($this));
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
            ['discount_id' => $discount->id, 'discount_type' => 'regular'],
            ['discount_amount' => $amount]
        );
    }

    public function applyMandatoryDiscount(\App\Models\MandatoryDiscount $mandatoryDiscount): SaleDiscount
    {
        // validate
        throw_if(! $mandatoryDiscount->is_active, \InvalidArgumentException::class, 'Mandatory discount is not active.');

        // calculate amount
        $amount = $mandatoryDiscount->type === 'percentage'
            ? round(($mandatoryDiscount->value / 100) * $this->total_amount, 2)
            : (float) $mandatoryDiscount->value;

        $amount = min(max($amount, 0), $this->total_amount);

        // upsert record - using the same table but with mandatory discount ID
        return $this->saleDiscounts()->updateOrCreate(
            ['discount_id' => $mandatoryDiscount->id, 'discount_type' => 'mandatory'],
            ['discount_amount' => $amount]
        );
    }
}
