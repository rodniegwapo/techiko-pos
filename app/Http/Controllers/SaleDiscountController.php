<?php

namespace App\Http\Controllers;

use App\Models\Product\Discount;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleDiscountController extends Controller
{
    public function applyOrderDiscount(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'discount_ids' => 'required|array',
            'discount_ids.*' => 'exists:discounts,id',
        ]);

        return DB::transaction(function () use ($validated, $sale) {
            $discountIds = $validated['discount_ids'];

            // delete all discounts if array empty,
            // or delete only those not in the submitted list
            $sale->saleDiscounts()
                ->when(! empty($discountIds), fn ($q) => $q->whereNotIn('discount_id', $discountIds))
                ->delete();

            $saleDiscounts = [];

            foreach ($discountIds as $discountId) {
                $discount = Discount::findOrFail($discountId);
                $saleDiscounts[] = $sale->applyOrderDiscount($discount);
            }

            // recalc once after all changes
            $sale->recalcTotals();

            return response()->json([
                'message' => 'Discount(s) applied',
                'sale_discounts' => $saleDiscounts,
                'sale' => $sale->fresh(['saleItems', 'saleDiscounts']),
            ]);
        });
    }

    public function removeOrderDiscount(Request $request, Sale $sale)
    {
        return DB::transaction(function () use ($sale) {
            // remove all sale-level discounts
            $sale->saleDiscounts()->delete();

            $sale->update([
                'discount_id' => null,
                'discount_amount' => 0,
            ]);

            $sale->recalcTotals();

            return response()->json([
                'message' => 'Discount(s) removed',
                'sale' => $sale->fresh(['saleItems', 'saleDiscounts']),
            ]);
        });
    }

    public function applyItemDiscount(Request $request, Sale $sale, SaleItem $saleItem)
    {
        if ($saleItem->sale_id !== $sale->id) {
            abort(404);
        }

        $validated = $request->validate([
            'discount_id' => 'required|integer|exists:discounts,id',
        ]);

        return DB::transaction(function () use ($validated, $saleItem) {
            $discount = Discount::findOrFail($validated['discount_id']);
            $saleItem->setDiscountAmount($discount->type, (float) $discount->value);

            $saleItem->discounts()->sync([$discount->id]);

            return response()->json([
                'message' => 'Item discount applied',
                'item' => $saleItem->fresh(),
            ]);
        });
    }

    public function removeItemDiscount(Request $request, Sale $sale, SaleItem $saleItem, Discount $discount)
    {
        if ($saleItem->sale_id !== $sale->id) {
            abort(404);
        }

        return DB::transaction(function () use ($saleItem, $discount) {
            // detach the discount
            $saleItem->discounts()->detach($discount->id);

            // reset discount amount on the item
            $saleItem->setDiscountAmount(null, 0);

            return response()->json([
                'message' => 'Item discount removed',
                'item' => $saleItem->fresh(),
            ]);
        });
    }
}
