<?php

namespace App\Http\Controllers;

use App\Http\Resources\StockAdjustmentResource;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\StockAdjustment;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class StockAdjustmentController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        // Middleware is handled at route level
        $this->inventoryService = $inventoryService;
    }

    /**
     * Reasons list shared by create / edit / domain create.
     *
     * @return array<string, string>
     */
    public static function adjustmentReasons(): array
    {
        return [
            'physical_count' => 'Physical Count',
            'damaged_goods' => 'Damaged Goods',
            'expired_goods' => 'Expired Goods',
            'theft_loss' => 'Theft/Loss',
            'supplier_error' => 'Supplier Error',
            'system_error' => 'System Error',
            'promotion' => 'Promotion',
            'sample' => 'Sample',
            'other' => 'Other',
        ];
    }

    /**
     * Locations shown in adjustment forms: all stores for super users, org stores only otherwise.
     */
    protected function adjustmentFormLocations(): \Illuminate\Database\Eloquent\Collection
    {
        $user = auth()->user();

        if ($user->is_super_user) {
            return InventoryLocation::active()->get();
        }

        if (empty($user->domain)) {
            return InventoryLocation::active()->whereRaw('1 = 0')->get();
        }

        return InventoryLocation::active()->forDomain($user->domain)->get();
    }

    /**
     * Domain options for global create (super = all; org user = single org or empty).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Domain>
     */
    protected function adjustmentFormDomains(): \Illuminate\Database\Eloquent\Collection
    {
        $user = auth()->user();
        $query = Domain::query()->select('id', 'name', 'name_slug');

        if ($user->is_super_user) {
            return $query->get();
        }

        if (empty($user->domain)) {
            return $query->whereRaw('1 = 0')->get();
        }

        return $query->where('name_slug', $user->domain)->get();
    }

    protected function isGlobalAdjustmentForm(): bool
    {
        return (bool) auth()->user()?->is_super_user;
    }

    protected function ensureAdjustmentAccessible(StockAdjustment $adjustment): void
    {
        $user = auth()->user();
        if ($user->is_super_user) {
            return;
        }

        $adjustment->loadMissing('location');

        if (empty($user->domain) || ! $adjustment->location || $adjustment->location->domain !== $user->domain) {
            abort(403, 'You cannot access this adjustment.');
        }
    }

    /**
     * Display a listing of stock adjustments
     */
    public function index(Request $request)
    {
        $query = StockAdjustment::with(['location', 'createdBy', 'approvedBy'])
            ->withCount('items')
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('status'), function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->input('location_id'), function ($query, $locationId) {
                return $query->where('location_id', $locationId);
            })
            ->when($request->input('date_from'), function ($query, $dateFrom) {
                return $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($request->input('date_to'), function ($query, $dateTo) {
                return $query->whereDate('created_at', '<=', $dateTo);
            })
            ->when($request->input('domain'), function ($query, $domain) {
                return $query->whereHas('location', fn($lq) => $lq->forDomain($domain));
            })
            ->orderBy('created_at', 'desc');

        $adjustments = $query->paginate($request->per_page ?? 20);

        return Inertia::render('Inventory/StockAdjustments/Index', [
            'adjustments' => StockAdjustmentResource::collection($adjustments),
            'locations' => InventoryLocation::active()->get(),
            'domains' => \App\Models\Domain::select('id', 'name', 'name_slug')->get(),
            'statuses' => [
                'draft' => 'Draft',
                'pending_approval' => 'Pending Approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
            ],
            'reasons' => static::adjustmentReasons(),
            'filters' => $request->only(['search', 'status', 'location_id', 'date_from', 'date_to', 'domain']),
            'isGlobalView' => true,
        ]);
    }

    /**
     * Show the form for creating a new stock adjustment
     */
    public function create()
    {
        return Inertia::render('Inventory/StockAdjustments/Create', [
            'locations' => $this->adjustmentFormLocations(),
            'reasons' => static::adjustmentReasons(),
            'domains' => $this->adjustmentFormDomains(),
            'isGlobalView' => $this->isGlobalAdjustmentForm(),
        ]);
    }

    /**
     * Store a newly created stock adjustment
     */
    public function store(Request $request)
    {
        $rules = [
            'location_id' => 'required|exists:inventory_locations,id',
            'type' => 'required|in:increase,decrease,recount',
            'reason' => 'required|in:physical_count,damaged_goods,expired_goods,theft_loss,supplier_error,system_error,promotion,sample,other',
            'description' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_quantity' => 'required|integer|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.batch_number' => 'nullable|string|max:255',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.notes' => 'nullable|string|max:500',
        ];

        // Add domain validation for global view
        if ($request->has('domain') && $request->domain) {
            $rules['domain'] = 'required|string|exists:domains,name_slug';
        }

        $validated = $request->validate($rules);

        $user = auth()->user();
        $location = InventoryLocation::query()->findOrFail($validated['location_id']);

        if (! $user->is_super_user) {
            if (empty($user->domain) || $location->domain !== $user->domain) {
                abort(403, 'You may only create adjustments for locations in your organization.');
            }
            $validated['domain'] = $user->domain;
        } else {
            $validated['domain'] = $validated['domain'] ?? $location->domain;
        }

        $adjustmentPayload = Arr::only($validated, [
            'location_id', 'type', 'reason', 'description', 'domain',
        ]);

        if (! $user->is_super_user && ! empty($user->domain)) {
            $productIds = collect($validated['items'])->pluck('product_id')->unique()->all();
            $invalidCount = Product::query()
                ->whereIn('id', $productIds)
                ->where('domain', '!=', $user->domain)
                ->count();
            if ($invalidCount > 0) {
                abort(403, 'One or more products are not in your organization.');
            }
        }

        try {
            $adjustment = $this->inventoryService->createStockAdjustment(
                $adjustmentPayload,
                $validated['items'],
                $user
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock adjustment created successfully',
                'adjustment' => $adjustment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock adjustment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified stock adjustment
     */
    public function show(Request $request, StockAdjustment $adjustment)
    {
        $this->ensureAdjustmentAccessible($adjustment);

        try {
            $adjustment->load([
                'location',
                'createdBy',
                'approvedBy',
                'items.product',
            ]);

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'adjustment' => $adjustment,
                ]);
            }

            // Return Inertia render for web requests
            return Inertia::render('Inventory/StockAdjustments/Show', [
                'adjustment' => $adjustment,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in StockAdjustmentController@show: '.$e->getMessage(), [
                'adjustment_id' => $adjustment->id ?? 'unknown',
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to load adjustment details: '.$e->getMessage(),
                ], 500);
            }

            abort(500, 'Failed to load adjustment details');
        }
    }

    /**
     * Show the form for editing the specified stock adjustment
     */
    public function edit(StockAdjustment $adjustment)
    {
        // Only allow editing of draft adjustments
        if ($adjustment->status !== 'draft') {
            abort(403, 'Only draft adjustments can be edited');
        }

        $this->ensureAdjustmentAccessible($adjustment);

        $adjustment->load([
            'location',
            'createdBy',
            'items.product',
        ]);

        return Inertia::render('Inventory/StockAdjustments/Edit', [
            'adjustment' => $adjustment,
            'locations' => $this->adjustmentFormLocations(),
            'reasons' => static::adjustmentReasons(),
        ]);
    }

    /**
     * Update the specified stock adjustment
     */
    public function update(Request $request, StockAdjustment $adjustment)
    {
        $this->ensureAdjustmentAccessible($adjustment);

        if ($adjustment->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft adjustments can be updated',
            ], 400);
        }

        $validated = $request->validate([
            'type' => 'required|in:increase,decrease,recount',
            'reason' => 'required|in:physical_count,damaged_goods,expired_goods,theft_loss,supplier_error,system_error,promotion,sample,other',
            'description' => 'nullable|string|max:1000',
        ]);

        try {
            $adjustment->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Stock adjustment updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock adjustment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit stock adjustment for approval
     */
    public function submitForApproval(Request $request, StockAdjustment $adjustment)
    {
        $this->ensureAdjustmentAccessible($adjustment);

        try {
            $adjustment->submitForApproval();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Stock adjustment submitted for approval',
                ]);
            }

            return redirect()->back()->with('success', 'Stock adjustment submitted for approval');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to submit for approval: '.$e->getMessage(),
                ], 400);
            }

            return redirect()->back()->with('error', 'Failed to submit for approval: '.$e->getMessage());
        }
    }

    /**
     * Approve stock adjustment
     */
    public function approve(Request $request, StockAdjustment $adjustment)
    {
        $this->ensureAdjustmentAccessible($adjustment);

        try {
            $adjustment->approve(auth()->user());

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Stock adjustment approved and processed',
                ]);
            }

            return redirect()->back()->with('success', 'Stock adjustment approved and processed');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to approve adjustment: '.$e->getMessage(),
                ], 400);
            }

            return redirect()->back()->with('error', 'Failed to approve adjustment: '.$e->getMessage());
        }
    }

    /**
     * Reject stock adjustment
     */
    public function reject(Request $request, StockAdjustment $adjustment)
    {
        $this->ensureAdjustmentAccessible($adjustment);

        try {
            $adjustment->reject();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Stock adjustment rejected',
                ]);
            }

            return redirect()->back()->with('success', 'Stock adjustment rejected');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to reject adjustment: '.$e->getMessage(),
                ], 400);
            }

            return redirect()->back()->with('error', 'Failed to reject adjustment: '.$e->getMessage());
        }
    }

    /**
     * Delete stock adjustment (only drafts)
     */
    public function destroy(StockAdjustment $adjustment)
    {
        $this->ensureAdjustmentAccessible($adjustment);

        if ($adjustment->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft adjustments can be deleted',
            ], 400);
        }

        try {
            $adjustment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock adjustment deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete adjustment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get products for adjustment with current stock levels
     */
    public function getProductsForAdjustment(Request $request)
    {
        try {
            $validated = $request->validate([
                'location_id' => 'required|exists:inventory_locations,id',
                'search' => 'nullable|string|max:255',
            ]);

            $locationId = $validated['location_id'];

            $location = InventoryLocation::query()->findOrFail($locationId);
            $user = auth()->user();

            if (! $user->is_super_user) {
                if (empty($user->domain) || $location->domain !== $user->domain) {
                    abort(403, 'You may only search products for locations in your organization.');
                }
            }

            $query = Product::with(['inventories' => function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            }])->where('track_inventory', true);

            if (! $user->is_super_user) {
                $query->where('domain', $user->domain);
            }

            if (! empty($validated['search'])) {
                $query->search($validated['search']);
            }

            $products = $query->limit(50)->get()->map(function ($product) {
                $inventory = $product->inventories->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'SKU' => $product->SKU,
                    'current_stock' => $inventory ? $inventory->quantity_on_hand : 0,
                    'unit_cost' => $inventory ? $inventory->average_cost : ($product->cost ?? 0),
                    'unit_of_measure' => $product->unit_of_measure ?? 'piece',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $products,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Error in getProductsForAdjustment: '.$e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products: '.$e->getMessage(),
                'data' => [],
            ], 500);
        }
    }
}
