<?php

namespace App\Http\Controllers;

use App\Models\Product\Discount;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\SaleDiscountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleDiscountController extends Controller
{
    public function applyOrderDiscount(Request $request, Sale $sale, SaleDiscountService $discountService)
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
            $regularDiscountIds = $validated['discount_ids'] ?? [];
            $mandatoryDiscountIds = $validated['mandatory_discount_ids'] ?? [];
            
            $result = $discountService->applyOrderDiscounts($sale, $regularDiscountIds, $mandatoryDiscountIds);

            return response()->json([
                'message' => 'Discount(s) applied',
                'sale_discounts' => $result['sale_discounts'],
                'sale' => $result['sale'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'discount' => [$e->getMessage()]
                ]
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while applying discounts: ' . $e->getMessage(),
                'errors' => [
                    'discount' => [$e->getMessage()]
                ]
            ], 500);
        }
    }

    public function removeOrderDiscount(Request $request, Sale $sale, SaleDiscountService $discountService)
    {
        try {
            $sale = $discountService->removeOrderDiscounts($sale);

            return response()->json([
                'message' => 'Discount(s) removed',
                'sale' => $sale,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while removing discounts: ' . $e->getMessage(),
                'errors' => [
                    'discount' => [$e->getMessage()]
                ]
            ], 500);
        }
    }

    public function applyItemDiscount(Request $request, Sale $sale, SaleItem $saleItem, SaleDiscountService $discountService)
    {
        if ($saleItem->sale_id !== $sale->id) {
            abort(404);
        }

        $validated = $request->validate([
            'discount_id' => 'required|integer|exists:discounts,id',
        ]);

        try {
            $result = $discountService->applyItemDiscount($sale, $saleItem, $validated['discount_id']);

            return response()->json([
                'message' => 'Item discount applied',
                'item' => $result['item'],
                'sale' => $result['sale'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while applying item discount: ' . $e->getMessage(),
                'errors' => [
                    'discount' => [$e->getMessage()]
                ]
            ], 500);
        }
    }

    public function removeItemDiscount(Request $request, Sale $sale, SaleItem $saleItem, Discount $discount, SaleDiscountService $discountService)
    {
        if ($saleItem->sale_id !== $sale->id) {
            abort(404);
        }

        try {
            $result = $discountService->removeItemDiscount($sale, $saleItem, $discount->id);

            return response()->json([
                'message' => 'Item discount removed',
                'item' => $result['item'],
                'sale' => $result['sale'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while removing item discount: ' . $e->getMessage(),
                'errors' => [
                    'discount' => [$e->getMessage()]
                ]
            ], 500);
        }
    }

}
