<?php

namespace App\Http\Controllers;

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
        $this->inventoryService = $inventoryService;
    }

    /**
     * Display a listing of stock adjustments
     */
    public function index(Request $request)
    {
        $query = StockAdjustment::with(['location', 'createdBy', 'approvedBy'])
            ->withCount('items')
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('adjustment_number', 'like', "%{$request->search}%")
                    ->orWhere('reason', 'like', "%{$request->search}%")
                    ->orWhere('notes', 'like', "%{$request->search}%");
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->location_id) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $adjustments = $query->paginate($request->per_page ?? 20);

        return Inertia::render('Inventory/StockAdjustments/Index', [
            'adjustments' => $adjustments,
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
    public function show(Request $request, StockAdjustment $stockAdjustment)
    {
        try {
            $stockAdjustment->load([
                'location',
                'createdBy',
                'approvedBy',
                'items.product',
            ]);

            // Return JSON for API requests
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'adjustment' => $stockAdjustment,
                ]);
            }

            // Return Inertia render for web requests
            return Inertia::render('Inventory/StockAdjustments/Show', [
                'adjustment' => $stockAdjustment,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in StockAdjustmentController@show: ' . $e->getMessage(), [
                'adjustment_id' => $stockAdjustment->id ?? 'unknown',
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to load adjustment details: ' . $e->getMessage(),
                ], 500);
            }

            abort(500, 'Failed to load adjustment details');
        }
    }

    /**
     * Update the specified stock adjustment
     */
    public function update(Request $request, StockAdjustment $stockAdjustment)
    {
        if ($stockAdjustment->status !== 'draft') {
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
            $stockAdjustment->update($validated);

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
    public function submitForApproval(Request $request, StockAdjustment $stockAdjustment)
    {
        try {
            $stockAdjustment->submitForApproval();

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
    public function approve(Request $request, StockAdjustment $stockAdjustment)
    {
        try {
            $stockAdjustment->approve(auth()->user());

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
    public function reject(Request $request, StockAdjustment $stockAdjustment)
    {
        try {
            $stockAdjustment->reject();

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
    public function destroy(StockAdjustment $stockAdjustment)
    {
        if ($stockAdjustment->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft adjustments can be deleted',
            ], 400);
        }

        try {
            $stockAdjustment->delete();

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
                $search = $validated['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('SKU', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
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
