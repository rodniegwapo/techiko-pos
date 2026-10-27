<?php

namespace App\Models;

use App\Models\Product\Discount;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'unit_cost' => 'decimal:4',
        // Whole for things sold by the piece; parts for things sold by weight or volume (1.5 kg).
        'quantity' => 'float',
        // Dine-in: how much of the line is already on a kitchen ticket.
        'sent_quantity' => 'float',
    ];

    protected static function booted()
    {
        // Freeze the product's cost on the line when it is first added, so later cost edits
        // don't rewrite the profit of sales already made.
        static::creating(function (SaleItem $item) {
            if ($item->unit_cost === null && $item->product_id) {
                $item->unit_cost = Product::whereKey($item->product_id)->value('cost');
            }
        });

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
        return $this->belongsTo(Product::class);
    }

    public function discounts()
    {
        return $this->belongsToMany(Discount::class);
    }

    /** The options picked on this line (Large, Extra shot…), as they were priced when rung up. */
    public function modifiers()
    {
        return $this->hasMany(SaleItemModifier::class)->orderBy('id');
    }

    /** @return list<array{group_name: string, name: string, price_delta: float}> */
    public function modifierSummary(): array
    {
        return $this->modifiers
            ->map(fn (SaleItemModifier $m) => [
                'group_name' => $m->group_name,
                'name' => $m->name,
                'price_delta' => round((float) $m->price_delta, 2),
            ])
            ->values()
            ->all();
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
