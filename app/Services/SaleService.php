<?php

namespace App\Services;

use App\Jobs\SyncSaleDraft;
use App\Models\InventoryLocation;
use App\Models\Product\Discount;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\UserPin;
use App\Models\VoidLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function storeDraft($user, $locationId = null)
    {
        // Determine location based on user role and context
        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;

        // Set location_id based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use provided location or current user's location
            $finalLocationId = $locationId ?? $currentUser->location_id;
        } else {
            // Regular users: Use their assigned location only
            $finalLocationId = $user->location_id;
        }

        $sale = Sale::create([
            'user_id' => $user->id,
            'location_id' => $finalLocationId,
            'domain' => $user->domain ?? 'default',
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
        if (empty($items) || ! is_array($items)) {
            return;
        }

        // Check inventory availability before processing
        $inventoryItems = collect($items)->map(function ($item) {
            return [
                'product_id' => $item['id'],
                'quantity' => max(1, (int) $item['quantity']),
            ];
        })->toArray();

        $unavailableItems = $this->inventoryService->checkStockAvailability($inventoryItems);

        if (! empty($unavailableItems)) {
            throw new \Exception('Some items are not available in sufficient quantities: '.
                collect($unavailableItems)->pluck('product_name')->implode(', '));
        }

        // Get all discount IDs to fetch in one query
        $discountIds = collect($items)
            ->pluck('discount_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $discounts = empty($discountIds) ? collect() : Discount::whereIn('id', $discountIds)->get()->keyBy('id');

        DB::transaction(function () use ($sale, $items, $discounts) {
            foreach ($items as $item) {
                // Validate required item fields
                if (! isset($item['id']) || ! isset($item['quantity']) || ! isset($item['price'])) {
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
                if (! empty($item['discount_id']) && $discounts->has($item['discount_id'])) {
                    $discount = $discounts->get($item['discount_id']);

                    // Validate discount is active and applicable
                    if ($discount->is_active &&
                        (! $discount->start_date || now()->gte($discount->start_date)) &&
                        (! $discount->end_date || now()->lte($discount->end_date))) {

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

    /**
     * Complete sale and process inventory
     */
    public function completeSale(Sale $sale, $user, ?InventoryLocation $location = null)
    {
        if ($sale->payment_status === 'paid') {
            throw new \Exception('Sale is already completed');
        }

        return DB::transaction(function () use ($sale, $user, $location) {
            // Prepare inventory items from sale items
            $inventoryItems = $sale->saleItems()->with('product')->get()->map(function ($saleItem) {
                return [
                    'product_id' => $saleItem->product_id,
                    'quantity' => $saleItem->quantity,
                    'unit_price' => $saleItem->unit_price,
                ];
            })->toArray();

            // Process inventory deduction
            $this->inventoryService->processSaleInventory($inventoryItems, $sale->id, $user, $location);

            // Update sale status
            $sale->update([
                'payment_status' => 'paid',
                'transaction_date' => now(),
            ]);

            return $sale;
        });
    }

    /**
     * Validate stock availability for sale items
     */
    public function validateStockAvailability(Sale $sale, ?InventoryLocation $location = null): array
    {
        $inventoryItems = $sale->saleItems()->with('product')->get()->map(function ($saleItem) {
            return [
                'product_id' => $saleItem->product_id,
                'quantity' => $saleItem->quantity,
            ];
        })->toArray();

        return $this->inventoryService->checkStockAvailability($inventoryItems, $location);
    }

    public function validateNewItemStockAvailability(array $newItem, ?InventoryLocation $location = null): array
    {
        return $this->inventoryService->checkStockAvailability([$newItem], $location);
    }

    /**
     * Handle overselling situations by creating automatic stock adjustments
     */
    public function handleOverselling(Sale $sale)
    {
        if (! $sale->location_id) {
            Log::warning('handleOverselling skipped: sale has no location_id', [
                'sale_id' => $sale->id,
            ]);

            return [];
        }

        $sale->loadMissing('saleItems.product', 'location', 'user');

        $oversoldItems = [];

        foreach ($sale->saleItems as $saleItem) {
            $productInventory = ProductInventory::query()
                ->where('product_id', $saleItem->product_id)
                ->where('location_id', $sale->location_id)
                ->first();

            $currentStock = $productInventory ? $productInventory->quantity_on_hand : 0;

            if ($currentStock < 0) {
                $oversoldItems[] = [
                    'product_id' => $saleItem->product_id,
                    'system_quantity' => 0,
                    'actual_quantity' => abs($currentStock),
                    'unit_cost' => $saleItem->product?->cost ?? $saleItem->unit_price ?? 0,
                    'notes' => "Oversold during sale #{$sale->invoice_number} - Customer purchased {$saleItem->quantity} units",
                ];
            }
        }

        if (! empty($oversoldItems)) {
            $this->createOversellAdjustment($sale, $oversoldItems);
        }

        return $oversoldItems;
    }

    /**
     * Create automatic stock adjustment for overselling situations
     */
    private function createOversellAdjustment(Sale $sale, array $oversoldItems)
    {
        $domain = $sale->domain
            ?? $sale->location?->domain
            ?? $sale->user?->domain
            ?? 'default';

        $adjustment = StockAdjustment::create([
            'adjustment_number' => StockAdjustment::generateAdjustmentNumber(),
            'type' => 'increase',
            'reason' => 'oversell_found',
            'description' => "Automatic adjustment for overselling in sale #{$sale->invoice_number}",
            'status' => 'approved',
            'created_by' => $sale->user_id,
            'approved_by' => $sale->user_id,
            'approved_at' => now(),
            'location_id' => $sale->location_id,
            'domain' => $domain,
        ]);

        foreach ($oversoldItems as $item) {
            $adjustment->items()->create(Arr::only($item, [
                'product_id',
                'system_quantity',
                'actual_quantity',
                'unit_cost',
                'notes',
            ]));
        }

        $adjustment->calculateTotalValueChange();
        $adjustment->applyInventoryFromApprovedAdjustment();

        Log::info('Oversell detected and adjusted', [
            'sale_id' => $sale->id,
            'invoice_number' => $sale->invoice_number,
            'adjustment_id' => $adjustment->id,
            'oversold_items' => count($oversoldItems),
        ]);

        return $adjustment;
    }

    /**
     * Get oversell statistics for management reporting
     */
    public function getOversellStatistics($startDate = null, $endDate = null, $domain = null)
    {
        $query = StockAdjustment::where('reason', 'oversell_found');

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        if ($domain) {
            $query->where('domain', $domain);
        }

        $adjustments = $query->with('items.product')->get();

        $statistics = [
            'total_oversells' => $adjustments->count(),
            'total_items_oversold' => $adjustments->sum(function ($adj) {
                return $adj->items->sum('adjustment_quantity');
            }),
            'total_value_oversold' => $adjustments->sum('total_value_change'),
            'products_affected' => $adjustments->flatMap(function ($adj) {
                return $adj->items->pluck('product.name');
            })->unique()->count(),
            'recent_oversells' => $adjustments->take(10)->map(function ($adj) {
                return [
                    'adjustment_number' => $adj->adjustment_number,
                    'created_at' => $adj->created_at,
                    'description' => $adj->description,
                    'items_count' => $adj->items->count(),
                    'total_value' => $adj->total_value_change,
                ];
            }),
        ];

        return $statistics;
    }
}
