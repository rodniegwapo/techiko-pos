<?php

namespace App\Services;

use App\Models\Product\Discount;
use App\Models\MandatoryDiscount;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaleDiscountService
{
    /**
     * Apply order-level discounts to a sale
     */
    public function applyOrderDiscounts(Sale $sale, array $regularDiscountIds = [], array $mandatoryDiscountIds = [])
    {
        Log::info("=== SaleDiscountService: Starting order discount application ===");
        Log::info("Sale ID: {$sale->id}");
        Log::info("Regular discount IDs: " . json_encode($regularDiscountIds));
        Log::info("Mandatory discount IDs: " . json_encode($mandatoryDiscountIds));

        return DB::transaction(function () use ($sale, $regularDiscountIds, $mandatoryDiscountIds) {
            // Step 1: Calculate the correct base amount for discounts
            $itemsSubtotal = $this->calculateItemsSubtotal($sale);
            Log::info("Calculated items subtotal: {$itemsSubtotal}");

            if ($itemsSubtotal <= 0) {
                Log::warning("Items subtotal is zero or negative. Cannot apply order discounts.");
                throw new \InvalidArgumentException('Cannot apply order discount: No items found in the order or all items have zero value. Please add items to the order first.');
            }

            // Step 2: Store original values and set temporary total for discount calculations
            $originalTotalAmount = $sale->total_amount;
            $originalDiscountAmount = $sale->discount_amount;
            
            Log::info("Original sale total_amount: {$originalTotalAmount}");
            Log::info("Original sale discount_amount: {$originalDiscountAmount}");

            // Temporarily set total_amount to items subtotal for proper discount calculation
            $sale->total_amount = $itemsSubtotal;
            Log::info("Set sale total_amount to items subtotal: {$sale->total_amount}");

            // Step 3: Clean up existing discounts
            $this->cleanupExistingDiscounts($sale, $regularDiscountIds, $mandatoryDiscountIds);

            // Step 4: Apply new discounts
            $saleDiscounts = [];
            
            // Apply regular discounts
            foreach ($regularDiscountIds as $discountId) {
                Log::info("Processing regular discount ID: {$discountId}");
                $discount = Discount::findOrFail($discountId);
                $saleDiscount = $this->applyRegularDiscount($sale, $discount);
                $saleDiscounts[] = $saleDiscount;
                Log::info("Applied regular discount: {$saleDiscount->discount_amount}");
            }

            // Apply mandatory discounts
            foreach ($mandatoryDiscountIds as $mandatoryDiscountId) {
                Log::info("Processing mandatory discount ID: {$mandatoryDiscountId}");
                $mandatoryDiscount = MandatoryDiscount::findOrFail($mandatoryDiscountId);
                $saleDiscount = $this->applyMandatoryDiscount($sale, $mandatoryDiscount);
                $saleDiscounts[] = $saleDiscount;
                Log::info("Applied mandatory discount: {$saleDiscount->discount_amount}");
            }

            // Step 5: Restore original total and recalculate everything properly
            $sale->total_amount = $originalTotalAmount;
            Log::info("Restored original total_amount: {$sale->total_amount}");
            
            $sale->recalcTotals();
            $sale->refresh();
            
            Log::info("Final sale totals after recalculation:");
            Log::info("- total_amount: {$sale->total_amount}");
            Log::info("- discount_amount: {$sale->discount_amount}");
            Log::info("- grand_total: {$sale->grand_total}");
            Log::info("=== SaleDiscountService: Order discount application completed ===");

            return [
                'sale_discounts' => $saleDiscounts,
                'sale' => $sale->fresh(['saleItems.product', 'saleDiscounts']),
            ];
        });
    }

    /**
     * Remove all order-level discounts from a sale
     */
    public function removeOrderDiscounts(Sale $sale)
    {
        Log::info("=== SaleDiscountService: Removing order discounts ===");
        Log::info("Sale ID: {$sale->id}");

        return DB::transaction(function () use ($sale) {
            // Remove all sale-level discounts
            $removedCount = $sale->saleDiscounts()->count();
            $sale->saleDiscounts()->delete();
            
            Log::info("Removed {$removedCount} order discounts");

            // Reset discount amount (discount_id column doesn't exist in sales table)
            $sale->update([
                'discount_amount' => 0,
            ]);

            $sale->recalcTotals();
            $sale->refresh();

            Log::info("Final totals after removing discounts:");
            Log::info("- total_amount: {$sale->total_amount}");
            Log::info("- discount_amount: {$sale->discount_amount}");
            Log::info("- grand_total: {$sale->grand_total}");
            Log::info("=== SaleDiscountService: Order discount removal completed ===");

            return $sale->fresh(['saleItems.product', 'saleDiscounts']);
        });
    }

    /**
     * Apply item-level discount to a sale item
     */
    public function applyItemDiscount(Sale $sale, SaleItem $saleItem, int $discountId)
    {
        Log::info("=== SaleDiscountService: Applying item discount ===");
        Log::info("Sale ID: {$sale->id}, SaleItem ID: {$saleItem->id}, Discount ID: {$discountId}");

        return DB::transaction(function () use ($sale, $saleItem, $discountId) {
            $discount = Discount::findOrFail($discountId);
            
            Log::info("Discount details: {$discount->name} ({$discount->type}: {$discount->value})");
            Log::info("Item before discount: unit_price={$saleItem->unit_price}, quantity={$saleItem->quantity}, discount={$saleItem->discount}");

            // Apply discount to the item
            $saleItem->setDiscountAmount($discount->type, (float) $discount->value);
            $saleItem->discounts()->sync([$discount->id]);
            $saleItem->refresh();

            Log::info("Item after discount: unit_price={$saleItem->unit_price}, quantity={$saleItem->quantity}, discount={$saleItem->discount}, subtotal={$saleItem->subtotal}");

            // Recalculate sale totals
            $sale->recalcTotals();
            $sale->refresh();

            Log::info("Sale totals after item discount:");
            Log::info("- total_amount: {$sale->total_amount}");
            Log::info("- discount_amount: {$sale->discount_amount}");
            Log::info("- grand_total: {$sale->grand_total}");
            Log::info("=== SaleDiscountService: Item discount application completed ===");

            return [
                'item' => $saleItem->fresh(['product']),
                'sale' => $sale->fresh(['saleItems.product', 'saleDiscounts']),
            ];
        });
    }

    /**
     * Remove item-level discount from a sale item
     */
    public function removeItemDiscount(Sale $sale, SaleItem $saleItem, int $discountId)
    {
        Log::info("=== SaleDiscountService: Removing item discount ===");
        Log::info("Sale ID: {$sale->id}, SaleItem ID: {$saleItem->id}, Discount ID: {$discountId}");

        return DB::transaction(function () use ($sale, $saleItem, $discountId) {
            Log::info("Item before removing discount: unit_price={$saleItem->unit_price}, quantity={$saleItem->quantity}, discount={$saleItem->discount}");
            
            // Check current discounts attached to this item
            $currentDiscounts = $saleItem->discounts()->get();
            Log::info("Current discounts attached to item: " . $currentDiscounts->count());
            foreach ($currentDiscounts as $discount) {
                Log::info("- Discount ID: {$discount->id}, Name: {$discount->name}");
            }

            // Remove the discount
            $detachedCount = $saleItem->discounts()->detach($discountId);
            Log::info("Detached {$detachedCount} discount relationships");
            
            $saleItem->setDiscountAmount(null, 0);
            $saleItem->refresh();

            Log::info("Item after removing discount: unit_price={$saleItem->unit_price}, quantity={$saleItem->quantity}, discount={$saleItem->discount}, subtotal={$saleItem->subtotal}");

            // Recalculate sale totals
            $sale->recalcTotals();
            $sale->refresh();

            Log::info("Sale totals after removing item discount:");
            Log::info("- total_amount: {$sale->total_amount}");
            Log::info("- discount_amount: {$sale->discount_amount}");
            Log::info("- grand_total: {$sale->grand_total}");
            Log::info("=== SaleDiscountService: Item discount removal completed ===");

            return [
                'item' => $saleItem->fresh(['product']),
                'sale' => $sale->fresh(['saleItems.product', 'saleDiscounts']),
            ];
        });
    }

    /**
     * Calculate the subtotal of all items (before order discounts)
     */
    private function calculateItemsSubtotal(Sale $sale): float
    {
        Log::info("--- Calculating items subtotal ---");
        
        // Check all items (including soft-deleted ones for debugging)
        $allItems = $sale->saleItems()->get();
        $activeItems = $sale->saleItems()->whereNull('deleted_at')->get();
        
        Log::info("Total items in sale: {$allItems->count()}");
        Log::info("Active items (not deleted): {$activeItems->count()}");
        
        if ($allItems->count() > 0 && $activeItems->count() === 0) {
            Log::warning("Sale has items but they are all soft-deleted!");
            foreach ($allItems as $item) {
                Log::info("Deleted item ID {$item->id}: deleted_at={$item->deleted_at}, unit_price={$item->unit_price}, quantity={$item->quantity}");
            }
        }

        if ($activeItems->count() === 0) {
            Log::warning("No active items found in sale. Cannot calculate subtotal.");
            return 0;
        }

        $subtotal = $activeItems->sum(function ($item) {
            $lineSubtotal = $item->unit_price * $item->quantity;
            $lineDiscount = $item->discount ?? 0;
            $result = max(0, $lineSubtotal - $lineDiscount);
            
            Log::info("Item ID {$item->id}: unit_price={$item->unit_price}, quantity={$item->quantity}, discount={$lineDiscount}, result={$result}");
            
            return $result;
        });

        Log::info("Total items subtotal: {$subtotal}");
        return $subtotal;
    }

    /**
     * Clean up existing discounts that are not in the new selection
     */
    private function cleanupExistingDiscounts(Sale $sale, array $regularDiscountIds, array $mandatoryDiscountIds)
    {
        Log::info("--- Cleaning up existing discounts ---");

        // Clean up regular discounts
        if (!empty($regularDiscountIds)) {
            $removedRegular = $sale->saleDiscounts()
                ->where('discount_type', 'regular')
                ->whereNotIn('discount_id', $regularDiscountIds)
                ->delete();
            Log::info("Removed {$removedRegular} regular discounts not in new selection");
        } else {
            $removedRegular = $sale->saleDiscounts()->where('discount_type', 'regular')->delete();
            Log::info("Removed all {$removedRegular} regular discounts (none selected)");
        }

        // Clean up mandatory discounts
        if (!empty($mandatoryDiscountIds)) {
            $removedMandatory = $sale->saleDiscounts()
                ->where('discount_type', 'mandatory')
                ->whereNotIn('discount_id', $mandatoryDiscountIds)
                ->delete();
            Log::info("Removed {$removedMandatory} mandatory discounts not in new selection");
        } else {
            $removedMandatory = $sale->saleDiscounts()->where('discount_type', 'mandatory')->delete();
            Log::info("Removed all {$removedMandatory} mandatory discounts (none selected)");
        }
    }

    /**
     * Apply a regular discount to the sale
     */
    private function applyRegularDiscount(Sale $sale, Discount $discount)
    {
        Log::info("--- Applying regular discount ---");
        Log::info("Discount: {$discount->name} ({$discount->type}: {$discount->value})");
        Log::info("Sale total_amount for calculation: {$sale->total_amount}");

        try {
            $saleDiscount = $sale->applyOrderDiscount($discount);
            Log::info("Successfully applied regular discount. Amount: {$saleDiscount->discount_amount}");
            return $saleDiscount;
        } catch (\Exception $e) {
            Log::error("Failed to apply regular discount: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Apply a mandatory discount to the sale
     */
    private function applyMandatoryDiscount(Sale $sale, MandatoryDiscount $mandatoryDiscount)
    {
        Log::info("--- Applying mandatory discount ---");
        Log::info("Mandatory Discount: {$mandatoryDiscount->name} ({$mandatoryDiscount->type}: {$mandatoryDiscount->value})");
        Log::info("Sale total_amount for calculation: {$sale->total_amount}");

        try {
            $saleDiscount = $sale->applyMandatoryDiscount($mandatoryDiscount);
            Log::info("Successfully applied mandatory discount. Amount: {$saleDiscount->discount_amount}");
            return $saleDiscount;
        } catch (\Exception $e) {
            Log::error("Failed to apply mandatory discount: " . $e->getMessage());
            throw $e;
        }
    }
}
