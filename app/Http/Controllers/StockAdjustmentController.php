<?php

namespace App\Http\Controllers;

use App\Http\Resources\StockAdjustmentResource;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\StockAdjustment;
use App\Services\InventoryService;
use Illuminate\Http\Request;
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
            ->orderBy('created_at', 'desc');

        $adjustments = $query->paginate($request->per_page ?? 20);

        return Inertia::render('Inventory/StockAdjustments/Index', [
            'adjustments' => StockAdjustmentResource::collection($adjustments),
            'locations' => InventoryLocation::active()->get(),
            'statuses' => [
                'draft' => 'Draft',
                'pending_approval' => 'Pending Approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
            ],
            'reasons' => [
                'physical_count' => 'Physical Count',
                'damaged_goods' => 'Damaged Goods',
                'expired_goods' => 'Expired Goods',
                'theft_loss' => 'Theft/Loss',
                'supplier_error' => 'Supplier Error',
                'system_error' => 'System Error',
                'promotion' => 'Promotion',
                'sample' => 'Sample',
                'other' => 'Other',
            ],
            'filters' => $request->only(['search', 'status', 'location_id', 'date_from', 'date_to']),
            'isGlobalView' => true,
        ]);
    }

    /**
     * Show the form for creating a new stock adjustment
     */
    public function create()
    {
        return Inertia::render('Inventory/StockAdjustments/Create', [
            'locations' => InventoryLocation::active()->get(),
            'reasons' => [
                'physical_count' => 'Physical Count',
                'damaged_goods' => 'Damaged Goods',
                'expired_goods' => 'Expired Goods',
                'theft_loss' => 'Theft/Loss',
                'supplier_error' => 'Supplier Error',
                'system_error' => 'System Error',
                'promotion' => 'Promotion',
                'sample' => 'Sample',
                'other' => 'Other',
            ],
        ]);
    }

    /**
     * Store a newly created stock adjustment
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
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
        ]);

        try {
            $adjustment = $this->inventoryService->createStockAdjustment(
                $validated,
                $validated['items'],
                auth()->user()
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

        $adjustment->load([
            'location',
            'createdBy',
            'items.product',
        ]);

        return Inertia::render('Inventory/StockAdjustments/Edit', [
            'adjustment' => $adjustment,
            'locations' => InventoryLocation::active()->get(),
            'reasons' => [
                'physical_count' => 'Physical Count',
                'damaged_goods' => 'Damaged Goods',
                'expired_goods' => 'Expired Goods',
                'theft_loss' => 'Theft/Loss',
                'supplier_error' => 'Supplier Error',
                'system_error' => 'System Error',
                'promotion' => 'Promotion',
                'sample' => 'Sample',
                'other' => 'Other',
            ],
        ]);
    }

    /**
     * Update the specified stock adjustment
     */
    public function update(Request $request, StockAdjustment $adjustment)
    {
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

            $query = Product::with(['inventories' => function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            }])->where('track_inventory', true);

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
