<?php

namespace App\Http\Controllers;

use App\Models\Product\Discount;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;

class SaleDiscountController extends Controller
{
    public function applyOrderDiscount(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'discount_id' => 'required|exists:discounts,id',
        ]);

        $discount = Discount::findOrFail($validated['discount_id']);
        $saleDiscount = $sale->applyOrderDiscount($discount);

        return response()->json([
            'message' => 'Discount applied',
            'sale_discount' => $saleDiscount,
            'sale' => $sale->fresh(['saleItems', 'saleDiscounts']),
        ]);
    }

    public function removeOrderDiscount(Request $request, Sale $sale)
    {
        $sale->update([
            'discount_id' => null,
            'discount_amount' => null,
        ]);

        return response()->json([
            'message' => 'Discount removed',
            'sale' => $sale->fresh(['sale']),
        ]);
    }

    public function applyItemDiscount(Request $request, Sale $sale, SaleItem $saleItem)
    {
        if ($saleItem->sale_id !== $sale->id) {
            abort(404);
        }

        $validated = $request->validate([
            'discount_id' => 'required|integer|exists:discounts,id',
        ]);

        $discount = Discount::findOrFail($validated['discount_id']);
        $saleItem->setDiscountAmount($discount->type, (float) $discount->value);

        $saleItem->discounts()->sync([$discount->id]);

        return response()->json([
            'message' => 'Item discount applied',
            'item' => $saleItem->fresh(),
        ]);
    }

    public function removeItemDiscount(Request $request, Sale $sale, SaleItem $saleItem, Discount $discount)
    {
        if ($saleItem->sale_id !== $sale->id) {
            abort(404);
        }

        // detach the discount
        $saleItem->discounts()->detach($discount->id);

        // reset discount amount on the item
        $saleItem->setDiscountAmount(null, 0);

        return response()->json([
            'message' => 'Item discount removed',
            'item' => $saleItem->fresh(),
        ]);
    }
}
