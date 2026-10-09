<?php

namespace App\Services;

use App\Jobs\SyncSaleDraft;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Discount;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\User;
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

        // Anyone not tied to one store (whatever their role is called) works in the store they picked
        if ($currentUser && ! $currentUser->hasLocationRestriction()) {
            // Use provided location or current user's location
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

        $inventoryLocation = $this->resolveInventoryLocationForSale($sale);
        $unavailableItems = $this->inventoryService->checkStockAvailability($inventoryItems, $inventoryLocation);

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
        // The line named, or (for callers that only know the product) the product's first line.
        $saleItem = $sale->saleItems()
            ->when(
                $validated['sale_item_id'] ?? null,
                fn ($q, $id) => $q->whereKey((int) $id),
                fn ($q) => $q->where('product_id', $validated['product_id'])->orderBy('id')
            )
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

    /**
     * Void a whole completed sale (from Sales History), approved with a manager PIN.
     * The sale stays on record as "voided", so it drops out of sales totals, the cash drawer and
     * reports, which all count paid sales only. Its stock goes back to the store it was sold from,
     * loyalty points are reversed, and a credit charge on it is cancelled — unless the customer has
     * already paid something on it, which has to be refunded by hand first.
     */
    public function voidSale(Sale $sale, User $currentUser, string $pinCode, string $reason): Sale
    {
        $approvedBy = $this->validatePin($currentUser, $pinCode);

        return DB::transaction(function () use ($sale, $currentUser, $approvedBy, $reason) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (! in_array($sale->payment_status, ['paid', 'partial'], true)) {
                throw ValidationException::withMessages([
                    'sale' => ['Only a completed sale can be voided.'],
                ]);
            }

            $this->cancelCreditCharges($sale);
            $this->returnSaleStock($sale, $currentUser);
            $this->reverseSaleLoyalty($sale);

            // One log line per item, so the Void Logs page lists what was on the receipt.
            foreach ($sale->saleItems()->get() as $item) {
                VoidLog::create([
                    'sale_item_id' => $item->id,
                    'user_id' => $currentUser->id,
                    'approver_id' => $approvedBy,
                    'reason' => 'Receipt voided: '.$reason,
                    'amount' => max(0, (float) $item->unit_price * (float) $item->quantity - (float) $item->discount),
                ]);
            }

            $sale->update([
                'payment_status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $currentUser->id,
                'void_approved_by' => $approvedBy,
                'void_reason' => $reason,
            ]);

            return $sale;
        });
    }

    /** Cancel what the sale charged on credit. Refuses if a payment was already put against it. */
    private function cancelCreditCharges(Sale $sale): void
    {
        $charges = CreditTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('transaction_type', 'credit')
            ->lockForUpdate()
            ->get();

        if ($charges->contains(fn (CreditTransaction $charge) => (float) $charge->paid_amount > 0)) {
            throw ValidationException::withMessages([
                'sale' => ['The customer has already paid part of this credit sale. Refund that payment in Credits before voiding the sale.'],
            ]);
        }

        foreach ($charges->whereNull('paid_at') as $charge) {
            $customer = Customer::query()->lockForUpdate()->find($charge->customer_id);
            if ($customer) {
                $customer->addCreditTransaction(
                    type: 'adjustment',
                    amount: -(float) $charge->amount,
                    saleId: $sale->id,
                    referenceNumber: $sale->invoice_number,
                    notes: "Sale voided - Invoice: {$sale->invoice_number}",
                );
            }

            // Closed, not paid: nothing is owed on it any more, and it no longer shows as due or overdue.
            $charge->update(['paid_at' => now(), 'notes' => trim(($charge->notes ?? '').' (voided)')]);
            $charge->installments()->whereNull('paid_at')->update(['paid_at' => now()]);
        }
    }

    /** Put back the stock the sale took, at the store it took it from. */
    private function returnSaleStock(Sale $sale, User $currentUser): void
    {
        $movements = InventoryMovement::query()
            ->where('reference_type', 'Sale')
            ->where('reference_id', $sale->id)
            ->where('movement_type', 'sale')
            ->get();

        foreach ($movements as $movement) {
            $this->inventoryService->recordMovement([
                'product_id' => $movement->product_id,
                'location_id' => $movement->location_id,
                'movement_type' => 'return',
                'quantity_change' => abs((float) $movement->quantity_change),
                'unit_cost' => $movement->unit_cost,
                'reference_type' => 'Sale',
                'reference_id' => $sale->id,
                'user_id' => $currentUser->id,
                'notes' => "Sale voided - Invoice: {$sale->invoice_number}",
            ]);
        }
    }

    /**
     * Take back the points the sale earned and give back the points spent on it, and undo its
     * spending stats. Points earned are worked out again from the sale total (they aren't stored),
     * and never take a customer below zero.
     */
    private function reverseSaleLoyalty(Sale $sale): void
    {
        if (! $sale->customer_id) {
            return;
        }

        $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
        if (! $customer) {
            return;
        }

        $amount = (float) $sale->grand_total;
        $changes = [
            'lifetime_spent' => max(0, (float) $customer->lifetime_spent - $amount),
            'total_purchases' => max(0, (int) $customer->total_purchases - 1),
        ];

        // A customer not enrolled in loyalty (no points balance) neither earned nor spent points.
        if ($customer->loyalty_points !== null) {
            $earned = $customer->calculatePointsForPurchase($amount);
            $redeemed = (int) ($sale->loyalty_points_redeemed ?? 0);
            $changes['loyalty_points'] = max(0, (int) $customer->loyalty_points - $earned) + $redeemed;
        }

        $customer->update($changes);
    }

    private function validatePin($currentUser, string $pinCode): int
    {
        if ($currentUser->hasAnyRole(['manager', 'admin'])) {
            return $this->validateManagerPin($currentUser->id, $pinCode);
        }

        if ($currentUser->hasRole('cashier')) {
            return $this->validateCashierPin($pinCode, $currentUser->domain);
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

    private function validateCashierPin(string $pinCode, ?string $domain): int
    {
        // Only a manager or admin of the cashier's own organization can approve.
        $managerPin = UserPin::whereHas('user', fn ($q) => $q->where('domain', $domain))
            ->whereHas('user.roles', function ($q) {
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
     * Inventory movements must use the sale's branch when no location is passed explicitly.
     */
    protected function resolveInventoryLocationForSale(Sale $sale, ?InventoryLocation $explicit = null): ?InventoryLocation
    {
        if ($explicit) {
            return $explicit;
        }

        if ($sale->location_id) {
            $fromSale = InventoryLocation::query()->find($sale->location_id);
            if ($fromSale) {
                return $fromSale;
            }
        }

        return InventoryLocation::getDefault($sale->domain)
            ?? InventoryLocation::getDefault();
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
            $inventoryLocation = $this->resolveInventoryLocationForSale($sale, $location);

            $inventoryLocation = $inventoryLocation
                ?? InventoryLocation::getDefault($sale->domain)
                ?? InventoryLocation::getDefault();

            // Prepare inventory items from sale items
            $inventoryItems = $sale->saleItems()->with('product')->get()->map(function ($saleItem) {
                return [
                    'product_id' => $saleItem->product_id,
                    'quantity' => $saleItem->quantity,
                    'unit_price' => $saleItem->unit_price,
                ];
            })->toArray();

            $allowsOverselling = true;
            if ($sale->domain) {
                $domainModel = Domain::findBySlug($sale->domain);
                if ($domainModel) {
                    $allowsOverselling = $domainModel->salesAllowsOverselling();
                }
            }

            if (! $allowsOverselling && $inventoryLocation) {
                $qtyItems = collect($inventoryItems)->map(fn (array $row) => [
                    'product_id' => $row['product_id'],
                    'quantity' => $row['quantity'],
                ])->all();

                $this->inventoryService->assertSufficientStockWithLocks($qtyItems, $inventoryLocation);
            }

            // Process inventory deduction
            $this->inventoryService->processSaleInventory($inventoryItems, $sale->id, $user, $inventoryLocation);

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

    public function oversellingAdjustmentEnabled(?string $domainSlug): bool
    {
        if (! $domainSlug) {
            return true;
        }

        return Domain::findBySlug($domainSlug)?->salesAllowsOverselling() ?? true;
    }

    /**
     * Block cart mutations when the domain disallows overselling and lines exceed available qty.
     */
    public function enforceNoOversellForPendingSale(Domain $domain, Sale $sale): void
    {
        if ($domain->salesAllowsOverselling()) {
            return;
        }

        $location = $this->resolveInventoryLocationForSale($sale);

        if (! $location) {
            return;
        }

        $aggregated = $sale->saleItems()->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => [
                'product_id' => (int) $rows->first()->product_id,
                'quantity' => (int) $rows->sum('quantity'),
            ])
            ->values()
            ->all();

        $short = $this->inventoryService->checkStockAvailability($aggregated, $location);

        if (! empty($short)) {
            $names = collect($short)->pluck('product_name')->implode(', ');
            throw ValidationException::withMessages([
                'stock' => ["Insufficient stock for: {$names}"],
            ]);
        }
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
