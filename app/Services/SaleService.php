<?php

namespace App\Services;

use App\Events\OrderUpdated;
use App\Jobs\SyncSaleDraft;
use App\Models\Sale;
use App\Models\UserPin;
use App\Models\VoidLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function storeDraft($user)
    {
        $sale = Sale::create([
            'user_id' => $user->id,
            'payment_status' => 'pending',
            'invoice_number' => Str::random(10),
            'transaction_date' => now(),
        ]);

        return $sale;
    }

    public function syncDraft(Sale $sale, array $items)
    {
        SyncSaleDraft::dispatch($sale, $items);
    }

    public function syncDraftImmediate(Sale $sale, array $items)
    {
        // Validate items array
        if (empty($items) || !is_array($items)) {
            return;
        }

        // Get all discount IDs to fetch in one query
        $discountIds = collect($items)
            ->pluck('discount_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $discounts = empty($discountIds) ? collect() : \App\Models\Product\Discount::whereIn('id', $discountIds)->get()->keyBy('id');

        \Illuminate\Support\Facades\DB::transaction(function () use ($sale, $items, $discounts) {
            foreach ($items as $item) {
                // Validate required item fields
                if (!isset($item['id']) || !isset($item['quantity']) || !isset($item['price'])) {
                    continue;
                }

                $saleItem = $sale->saleItems()->updateOrCreate(
                    ['product_id' => $item['id']],
                    [
                        'quantity' => max(1, (int) $item['quantity']),
                        'unit_price' => max(0, (float) $item['price']),
                    ]
                );

                // Handle discounts if provided
                if (!empty($item['discount_id']) && $discounts->has($item['discount_id'])) {
                    $discount = $discounts->get($item['discount_id']);
                    
                    // Validate discount is active and applicable
                    if ($discount->is_active && 
                        (!$discount->start_date || now()->gte($discount->start_date)) &&
                        (!$discount->end_date || now()->lte($discount->end_date))) {
                        
                        $saleItem->setDiscountAmount($discount->type, (float) $discount->value);
                        $saleItem->discounts()->sync([$discount->id]);
                    } else {
                        // Discount is not valid, clear any existing discounts
                        $saleItem->setDiscountAmount(null, 0);
                        $saleItem->discounts()->detach();
                    }
                } else {
                    $saleItem->setDiscountAmount(null, 0);
                    $saleItem->discounts()->detach();
                }
            }

            // Recalculate sale totals
            $sale->recalcTotals();
        });
    }

    public function voidItem(Sale $sale, array $validated, $currentUser)
    {
        $saleItem = $sale->saleItems()
            ->where('product_id', $validated['product_id'])
            ->firstOrFail();

        // Check PIN and get approver
        $approvedBy = $this->validatePin($currentUser, $validated['pin_code']);

        // Create log
        VoidLog::create([
            'sale_item_id' => $saleItem->id,
            'user_id' => $currentUser->id,
            'approver_id' => $approvedBy,
            'reason' => $validated['reason'] ?? null,
            'amount' => $saleItem->unit_price,
        ]);

        // Soft delete
        $saleItem->delete();

        // Recalculate sale totals after voiding the item
        $sale->recalcTotals();

        return $saleItem;
    }

    private function validatePin($currentUser, string $pinCode): int
    {
        if ($currentUser->hasAnyRole(['manager', 'admin'])) {
            return $this->validateManagerPin($currentUser->id, $pinCode);
        }

        if ($currentUser->hasRole('cashier')) {
            return $this->validateCashierPin($pinCode);
        }

        throw ValidationException::withMessages([
            'pin_code' => ['You are not authorized to void items.'],
        ]);
    }

    private function validateManagerPin(int $userId, string $pinCode): int
    {
        $userPin = UserPin::where('user_id', $userId)->first();

        if (! $userPin || ! Hash::check($pinCode, $userPin->pin_code)) {
            throw ValidationException::withMessages([
                'pin_code' => ['The provided Pin Code is incorrect.'],
            ]);
        }

        return $userId;
    }

    private function validateCashierPin(string $pinCode): int
    {
        $managerPin = UserPin::whereHas('user.roles', function ($q) {
            $q->whereIn('name', ['manager', 'admin']);
        })->get()
            ->first(fn ($pin) => Hash::check($pinCode, $pin->pin_code));

        if (! $managerPin) {
            throw ValidationException::withMessages([
                'pin_code' => ['The provided Pin Code is incorrect.'],
            ]);
        }

        return $managerPin->user_id;
    }
}
