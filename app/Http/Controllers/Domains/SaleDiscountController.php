<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Sale;
use App\Services\SaleDiscountService;
use Illuminate\Http\Request;

class SaleDiscountController extends Controller
{
    /**
     * Get sale-specific discount state
     */
    public function getSaleDiscounts(Request $request, Domain $domain, Sale $sale)
    {
        $sale->load(['saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        $regularDiscounts = $sale->saleDiscounts()
            ->where('discount_type', 'regular')
            ->with('discount')
            ->get()
            ->pluck('discount');

        $mandatoryDiscounts = $sale->saleDiscounts()
            ->where('discount_type', 'mandatory')
            ->with('mandatoryDiscount')
            ->get()
            ->pluck('mandatoryDiscount');

        return response()->json([
            'success' => true,
            'regular_discounts' => $regularDiscounts,
            'mandatory_discounts' => $mandatoryDiscounts,
            'total_discount_amount' => $sale->discount_amount
        ]);
    }

    /**
     * Update sale discounts
     */
    public function updateSaleDiscounts(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'regular_discount_ids' => 'array',
            'regular_discount_ids.*' => 'exists:discounts,id',
            'mandatory_discount_ids' => 'array',
            'mandatory_discount_ids.*' => 'exists:mandatory_discounts,id'
        ]);

        try {
            $saleDiscountService = app(\App\Services\SaleDiscountService::class);
            $result = $saleDiscountService->applyOrderDiscounts(
                $sale,
                $validated['regular_discount_ids'] ?? [],
                $validated['mandatory_discount_ids'] ?? []
            );

            return response()->json([
                'success' => true,
                'sale' => $result['sale'],
                'discounts' => $result['sale_discounts'],
                'discount_amount' => $result['sale']->discount_amount ?? 0
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Remove all discounts from a sale
     */
    public function removeSaleDiscounts(Request $request, Domain $domain, Sale $sale)
    {
        try {
            $saleDiscountService = app(\App\Services\SaleDiscountService::class);
            $sale = $saleDiscountService->removeOrderDiscounts($sale);

            return response()->json([
                'success' => true,
                'message' => 'All discounts removed successfully',
                'sale' => $sale->load(['saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Apply order discount
     */
    public function applyOrderDiscount(Request $request, Domain $domain, Sale $sale, SaleDiscountService $discountService)
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

    /**
     * Remove order discount
     */
    public function removeOrderDiscount(Request $request, Domain $domain, Sale $sale, SaleDiscountService $discountService)
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

    /**
     * Apply item-level discount
     */
    public function applyItemDiscount(Request $request, Domain $domain, Sale $sale, $saleItem)
    {
        $validated = $request->validate([
            'discount_id' => 'required|integer|exists:discounts,id',
        ]);

        try {
            $saleItem = $sale->saleItems()->findOrFail($saleItem);
            $saleDiscountService = app(\App\Services\SaleDiscountService::class);
            
            $result = $saleDiscountService->applyItemDiscount($sale, $saleItem, $validated['discount_id']);

            return response()->json([
                'success' => true,
                'message' => 'Item discount applied successfully',
                'item' => $result['item'],
                'sale' => $result['sale']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply item discount: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Remove item-level discount
     */
    public function removeItemDiscount(Request $request, Domain $domain, Sale $sale, $saleItem)
    {
        $validated = $request->validate([
            'discount_id' => 'required|integer|exists:discounts,id',
        ]);

        try {
            $saleItem = $sale->saleItems()->findOrFail($saleItem);
            $saleDiscountService = app(\App\Services\SaleDiscountService::class);
            
            $result = $saleDiscountService->removeItemDiscount($sale, $saleItem, $validated['discount_id']);

            return response()->json([
                'success' => true,
                'message' => 'Item discount removed successfully',
                'item' => $result['item'],
                'sale' => $result['sale']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove item discount: ' . $e->getMessage()
            ], 400);
        }
    }
}
