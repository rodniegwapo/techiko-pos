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
            'discount_ids' => 'sometimes|array',
            'discount_ids.*' => 'integer|exists:discounts,id',
            'mandatory_discount_ids' => 'sometimes|array',
            'mandatory_discount_ids.*' => 'integer|exists:mandatory_discounts,id',
        ]);

        // Ensure at least one discount type is provided
        if (empty($validated['discount_ids']) && empty($validated['mandatory_discount_ids'])) {
            throw new \InvalidArgumentException('At least one discount must be provided.');
        }

        try {
            return DB::transaction(function () use ($validated, $sale) {
                $regularDiscountIds = $validated['discount_ids'] ?? [];
                $mandatoryDiscountIds = $validated['mandatory_discount_ids'] ?? [];

                // Clean up regular discounts not in the submitted list
                if (!empty($regularDiscountIds)) {
                    $sale->saleDiscounts()
                        ->where('discount_type', 'regular')
                        ->whereNotIn('discount_id', $regularDiscountIds)
                        ->delete();
                } else {
                    // Remove all regular discounts if none submitted
                    $sale->saleDiscounts()->where('discount_type', 'regular')->delete();
                }

                // Clean up mandatory discounts not in the submitted list
                if (!empty($mandatoryDiscountIds)) {
                    $sale->saleDiscounts()
                        ->where('discount_type', 'mandatory')
                        ->whereNotIn('discount_id', $mandatoryDiscountIds)
                        ->delete();
                } else {
                    // Remove all mandatory discounts if none submitted
                    $sale->saleDiscounts()->where('discount_type', 'mandatory')->delete();
                }

                $saleDiscounts = [];

                // Process regular discounts
                foreach ($regularDiscountIds as $discountId) {
                    $discount = Discount::findOrFail($discountId);
                    $saleDiscounts[] = $sale->applyOrderDiscount($discount);
                }

                // Process mandatory discounts
                foreach ($mandatoryDiscountIds as $mandatoryDiscountId) {
                    $mandatoryDiscount = \App\Models\MandatoryDiscount::findOrFail($mandatoryDiscountId);
                    $saleDiscounts[] = $sale->applyMandatoryDiscount($mandatoryDiscount);
                }

                // recalc once after all changes
                $sale->recalcTotals();

                return response()->json([
                    'message' => 'Discount(s) applied',
                    'sale_discounts' => $saleDiscounts,
                    'sale' => $sale->fresh(['saleItems', 'saleDiscounts']),
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'discount' => [$e->getMessage()]
                ]
            ], 400);
        }
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
