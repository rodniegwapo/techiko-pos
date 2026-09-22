<?php

namespace App\Http\Controllers\Domains\Inventory;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\StockAdjustmentController as GlobalStockAdjustmentController;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\StockAdjustment;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class StockAdjustmentController extends Controller
{
    public function __construct(private InventoryService $inventoryService) {}

    /** 1–100 per page; anything else (missing, 0, "abc") falls back to 20. */
    private function perPage(Request $request): int
    {
        $perPage = filter_var($request->input('per_page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 20;

        return min($perPage, 100);
    }

    /**
     * List adjustments scoped to domain
     */
    public function index(Request $request, Domain $domain)
    {
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));
        $perPage = $this->perPage($request);

        if (! $location) {
            $empty = new LengthAwarePaginator([], 0, $perPage);

            return Inertia::render('Inventory/StockAdjustments/Index', [
                'adjustments' => StockAdjustmentResource::collection($empty),
                'locations' => InventoryLocation::active()->forDomain($domain->name_slug)->get(),
                'statuses' => [
                    'draft' => 'Draft',
                    'pending_approval' => 'Pending Approval',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ],
                'filters' => $request->only(['search', 'status', 'location_id', 'date_from', 'date_to']),
                'isGlobalView' => false,
            ]);
        }

        $query = StockAdjustment::with(['location', 'createdBy', 'approvedBy'])
            ->withCount('items')
            ->where('location_id', $location->id)
            ->when($request->input('search'), fn ($q, $s) => $q->search($s))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('date_from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->input('date_to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderBy('created_at', 'desc');

        $adjustments = $query->paginate($perPage);

        return Inertia::render('Inventory/StockAdjustments/Index', [
            'adjustments' => StockAdjustmentResource::collection($adjustments),
            'locations' => InventoryLocation::active()->forDomain($domain->name_slug)->get(),
            'statuses' => [
                'draft' => 'Draft',
                'pending_approval' => 'Pending Approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
            ],
            'filters' => $request->only(['search', 'status', 'location_id', 'date_from', 'date_to']),
            'isGlobalView' => false,
        ]);
    }

    /**
     * Show the form for creating a stock adjustment within this domain.
     */
    public function create(Domain $domain)
    {
        return Inertia::render('Inventory/StockAdjustments/Create', [
            'locations' => InventoryLocation::active()->forDomain($domain->name_slug)->get(),
            'reasons' => GlobalStockAdjustmentController::adjustmentReasons(),
            'domains' => Domain::query()
                ->select('id', 'name', 'name_slug')
                ->where('name_slug', $domain->name_slug)
                ->get(),
            'isGlobalView' => false,
            'currentDomain' => $domain,
        ]);
    }

    public function show(Request $request, Domain $domain, StockAdjustment $adjustment)
    {
        // Ensure adjustment location belongs to domain
        if (! $adjustment->location || $adjustment->location->domain !== $domain->name_slug) {
            abort(403, 'Adjustment does not belong to this domain');
        }

        $adjustment->load(['location', 'createdBy', 'approvedBy', 'items.product']);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'adjustment' => new StockAdjustmentResource($adjustment),
            ]);
        }

        // Adjustments are read on the list's details dialog; there is no standalone page.
        return redirect()->route('domains.inventory.adjustments.index', [
            'domain' => $domain->name_slug,
            'location_id' => $adjustment->location_id,
            'search' => $adjustment->adjustment_number,
        ]);
    }

    public function store(Request $request, Domain $domain)
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

        // Domain guard on location
        $location = InventoryLocation::forDomain($domain->name_slug)->findOrFail($validated['location_id']);
        $validated['domain'] = $location->domain ?? $domain->name_slug;

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
    }

    public function update(Request $request, Domain $domain, StockAdjustment $adjustment)
    {
        if ($adjustment->location->domain !== $domain->name_slug) {
            abort(403, 'Adjustment does not belong to this domain');
        }

        if ($adjustment->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft adjustments can be updated'], 400);
        }

        $validated = $request->validate([
            'type' => 'required|in:increase,decrease,recount',
            'reason' => 'required|in:physical_count,damaged_goods,expired_goods,theft_loss,supplier_error,system_error,promotion,sample,other',
            'description' => 'nullable|string|max:1000',
        ]);

        $adjustment->update($validated);

        return response()->json(['success' => true, 'message' => 'Stock adjustment updated successfully']);
    }

    public function destroy(Request $request, Domain $domain, StockAdjustment $adjustment)
    {
        if ($adjustment->location->domain !== $domain->name_slug) {
            abort(403, 'Adjustment does not belong to this domain');
        }
        if ($adjustment->status !== 'draft') {
            return $this->respond($request, false, 'Only draft adjustments can be deleted', 400);
        }
        $adjustment->delete();

        return $this->respond($request, true, 'Stock adjustment deleted successfully');
    }

    public function submitForApproval(Request $request, Domain $domain, StockAdjustment $adjustment)
    {
        if ($adjustment->location->domain !== $domain->name_slug) {
            abort(403, 'Adjustment does not belong to this domain');
        }

        try {
            $adjustment->submitForApproval();
        } catch (\Exception $e) {
            return $this->respond($request, false, 'Failed to submit for approval: '.$e->getMessage(), 400);
        }

        return $this->respond($request, true, 'Stock adjustment submitted for approval');
    }

    public function approve(Request $request, Domain $domain, StockAdjustment $adjustment)
    {
        if ($adjustment->location->domain !== $domain->name_slug) {
            abort(403, 'Adjustment does not belong to this domain');
        }

        try {
            $adjustment->approve(auth()->user());
        } catch (\Exception $e) {
            return $this->respond($request, false, 'Failed to approve adjustment: '.$e->getMessage(), 400);
        }

        return $this->respond($request, true, 'Stock adjustment approved and processed');
    }

    public function reject(Request $request, Domain $domain, StockAdjustment $adjustment)
    {
        if ($adjustment->location->domain !== $domain->name_slug) {
            abort(403, 'Adjustment does not belong to this domain');
        }

        try {
            $adjustment->reject();
        } catch (\Exception $e) {
            return $this->respond($request, false, 'Failed to reject adjustment: '.$e->getMessage(), 400);
        }

        return $this->respond($request, true, 'Stock adjustment rejected');
    }

    /**
     * These actions are called both from the page (Inertia, which needs a redirect it can follow)
     * and as plain JSON from axios, so the answer follows what the caller asked for.
     */
    private function respond(Request $request, bool $success, string $message, int $status = 200)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => $success, 'message' => $message], $status);
        }

        return redirect()->back()->with($success ? 'success' : 'error', $message);
    }

    public function getProductsForAdjustment(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:inventory_locations,id',
            'search' => 'nullable|string|max:255',
        ]);

        $locationId = $validated['location_id'];
        // Ensure location in domain
        InventoryLocation::forDomain($domain->name_slug)->findOrFail($locationId);

        $query = Product::with(['inventories' => function ($q) use ($locationId) {
            $q->where('location_id', $locationId);
        }])
            ->where('domain', $domain->name_slug)
            ->where('track_inventory', true);

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

        return response()->json(['success' => true, 'data' => $products]);
    }
}
