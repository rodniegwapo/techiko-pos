<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\ProductInventoryResource;
use App\Models\Domain;
use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        // Middleware is handled at route level
        $this->inventoryService = $inventoryService;
    }

    /**
     * Display inventory overview
     */
    public function index(Request $request, Domain $domain = null)
    {
        $domainSlug = $domain?->name_slug;
        $isDomainRoute = (bool) $domain;

        $location = null;
        if ($request->location_id) {
            $location = InventoryLocation::when($domainSlug, fn($q) => $q->forDomain($domainSlug))
                ->findOrFail($request->location_id);
        }

        $report = $this->inventoryService->getInventoryReport($location, $domainSlug);

        return Inertia::render('Inventory/Index', [
            'report' => $report,
            'locations' => InventoryLocation::active()
                ->when($domainSlug, fn($q) => $q->forDomain($domainSlug))
                ->get(),
            'currentLocation' => $location,
            'currentDomain' => $domain,
            'isGlobalView' => ! $isDomainRoute,
        ]);
    }

    /**
     * Display inventory levels for products
     */
    public function products(Request $request, Domain $domain = null)
    {
        $domainSlug = $domain?->name_slug;
        $isDomainRoute = (bool) $domain;

        $location = $request->location_id
            ? InventoryLocation::when($domainSlug, fn($q) => $q->forDomain($domainSlug))->findOrFail($request->location_id)
            : (function () use ($domainSlug) {
                $q = InventoryLocation::active();
                if ($domainSlug) $q->forDomain($domainSlug);
                return $q->first() ?? InventoryLocation::getDefault();
            })();

        $query = ProductInventory::with(['product', 'location', 'product.category'])
            ->where('location_id', $location->id);

        // Apply filters
        if ($request->search) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->search($request->search);
            });
        }

        if ($request->stock_status) {
            switch ($request->stock_status) {
                case 'in_stock':
                    $query->where('quantity_available', '>', 0);
                    break;
                case 'low_stock':
                    $query->whereRaw('quantity_available <= (SELECT reorder_level FROM products WHERE products.id = product_inventory.product_id)');
                    break;
                case 'out_of_stock':
                    $query->where('quantity_available', '<=', 0);
                    break;
            }
        }

        if ($request->category_id) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        // Domain filtering for global views - use product domain instead of location domain
        if ($request->domain && !$isDomainRoute) {
            $query->whereHas('product', fn($pq) => $pq->where('domain', $request->domain));
        }

        $inventories = $query->orderBy('quantity_available', 'asc')
            ->paginate($request->per_page ?? 20);

        // Check if this is an API request
        if ($request->expectsJson() || $request->is('api/*')) {
            // Add location-specific stock status to each inventory item
            $inventoryData = $inventories->items();
            foreach ($inventoryData as $inventory) {
                $inventory->location_stock_status = $inventory->getStockStatus();
            }

            return response()->json([
                'success' => true,
                'data' => $inventoryData,
                'pagination' => [
                    'current_page' => $inventories->currentPage(),
                    'last_page' => $inventories->lastPage(),
                    'per_page' => $inventories->perPage(),
                    'total' => $inventories->total(),
                ],
                'current_location' => $location,
            ]);
        }

        // Add location-specific stock status to each inventory item for web response
        $inventories->getCollection()->transform(function ($inventory) {
            $inventory->location_stock_status = $inventory->getStockStatus();
            return $inventory;
        });

        return Inertia::render('Inventory/Products', [
            'inventories' => ProductInventoryResource::collection($inventories),
            'locations' => InventoryLocation::active()->when($domainSlug, fn($q) => $q->forDomain($domainSlug))->get(),
            'currentLocation' => $location,
            'categories' => \App\Models\Category::all(),
            'domains' => $isDomainRoute ? [] : Domain::select('id', 'name', 'name_slug')->get(),
            'filters' => $request->only(['search', 'stock_status', 'category_id', 'domain']),
            'currentDomain' => $domain,
            'isGlobalView' => ! $isDomainRoute,
        ]);
    }

    /**
     * Display inventory movements/history
     */
    public function movements(Request $request, Domain $domain = null)
    {
        try {
            $domainSlug = $domain?->name_slug;
            $isDomainRoute = (bool) $domain;

            $query = InventoryMovement::with(['product', 'location', 'user'])
                ->when($domainSlug, function ($q) use ($domainSlug) {
                    return $q->where('domain', $domainSlug);
                })
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($request->search) {
                $query->search($request->search);
            }

            if ($request->location_id) {
                $query->where('location_id', $request->location_id);
            }

            if ($request->product_id) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->movement_type) {
                $query->where('movement_type', $request->movement_type);
            }

            if ($request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Domain filtering for global views - use movement domain directly
            if ($request->domain && !$isDomainRoute) {
                $query->where('domain', $request->domain);
            }

            $movements = $query->paginate($request->per_page ?? 50);

            return Inertia::render('Inventory/Movements', [
                'movements' => InventoryMovementResource::collection($movements),
                'locations' => InventoryLocation::active()
                    ->when($domainSlug, fn($q) => $q->forDomain($domainSlug))
                    ->get(),
                'products' => Product::select('id', 'name', 'SKU')->get(),
                'domains' => $isDomainRoute ? [] : Domain::select('id', 'name', 'name_slug')->get(),
                'movementTypes' => [
                    'sale' => 'Sale',
                    'purchase' => 'Purchase',
                    'adjustment' => 'Stock Adjustment',
                    'transfer_in' => 'Transfer In',
                    'transfer_out' => 'Transfer Out',
                    'return' => 'Customer Return',
                    'damage' => 'Damaged Goods',
                    'theft' => 'Theft/Loss',
                    'expired' => 'Expired Products',
                    'promotion' => 'Promotional Giveaway',
                ],
                'filters' => $request->only(['search', 'location_id', 'product_id', 'movement_type', 'date_from', 'date_to', 'domain']),
                'currentDomain' => $domain,
                'isGlobalView' => ! $isDomainRoute,
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to load movements: ' . $e->getMessage());
        }
    }

    /**
     * Receive inventory (from purchase orders, manual entry)
     */
    public function receive(Request $request)
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:inventory_locations,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.batch_number' => 'nullable|string|max:255',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.notes' => 'nullable|string|max:500',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
        ]);

        try {
            $this->inventoryService->receiveInventory(
                $validated['items'],
                auth()->user(),
                InventoryLocation::find($validated['location_id']),
                $validated['reference_type'] ?? null,
                $validated['reference_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Inventory received successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to receive inventory: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Transfer inventory between locations
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'from_location_id' => 'required|exists:inventory_locations,id',
            'to_location_id' => 'required|exists:inventory_locations,id|different:from_location_id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $product = Product::findOrFail($validated['product_id']);
            $fromLocation = InventoryLocation::findOrFail($validated['from_location_id']);
            $toLocation = InventoryLocation::findOrFail($validated['to_location_id']);

            $this->inventoryService->transferInventory(
                $product,
                $fromLocation,
                $toLocation,
                $validated['quantity'],
                auth()->user(),
                $validated['notes'] ?? null
            );

            return response()->noContent(200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to transfer inventory: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get low stock products
     */
    public function lowStock(Request $request, Domain $domain = null)
    {
        $domainSlug = $domain?->name_slug;
        $location = $request->location_id
            ? InventoryLocation::when($domainSlug, fn($q) => $q->forDomain($domainSlug))->findOrFail($request->location_id)
            : null;

        $lowStockProducts = $this->inventoryService->getLowStockProducts($location);

        return response()->json([
            'products' => $lowStockProducts->map(function ($product) use ($location) {
                $inventory = $location
                    ? $product->inventoryAt($location)
                    : $product->defaultInventory();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->SKU,
                    'current_stock' => $inventory ? $inventory->quantity_available : 0,
                    'reorder_level' => $product->reorder_level,
                    'shortage' => max(0, $product->reorder_level - ($inventory ? $inventory->quantity_available : 0)),
                ];
            }),
        ]);
    }

    /**
     * Get inventory valuation report
     */
    public function valuation(Request $request, Domain $domain = null)
    {
        $domainSlug = $domain?->name_slug;
        $location = $request->location_id
            ? InventoryLocation::when($domainSlug, fn($q) => $q->forDomain($domainSlug))->findOrFail($request->location_id)
            : (function () use ($domainSlug) {
                $q = InventoryLocation::active();
                if ($domainSlug) $q->forDomain($domainSlug);
                return $q->first() ?? InventoryLocation::getDefault();
            })();

        $inventories = ProductInventory::with('product')
            ->where('location_id', $location->id)
            ->where('quantity_on_hand', '>', 0)
            ->get();

        $totalValue = $inventories->sum('total_value');
        $totalQuantity = $inventories->sum('quantity_on_hand');

        $valuationData = $inventories->map(function ($inventory) {
            return [
                'product_id' => $inventory->product_id,
                'product_name' => $inventory->product->name,
                'sku' => $inventory->product->SKU,
                'quantity_on_hand' => $inventory->quantity_on_hand,
                'average_cost' => $inventory->average_cost,
                'total_value' => $inventory->total_value,
                'last_movement_at' => $inventory->last_movement_at,
            ];
        });

        return Inertia::render('Inventory/Valuation', [
            'location' => $location,
            'summary' => [
                'total_value' => $totalValue,
                'total_quantity' => $totalQuantity,
                'total_products' => $inventories->count(),
            ],
            'items' => $valuationData,
            'locations' => InventoryLocation::active()->get(),
        ]);
    }

    /**
     * Get store summary for create pages
     */
    public function getLocationSummary(Request $request, $locationId)
    {
        $location = InventoryLocation::findOrFail($locationId);

        $summary = [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'total_products_count' => ProductInventory::where('location_id', $location->id)->count(),
            'in_stock_products_count' => ProductInventory::where('location_id', $location->id)
                ->where('quantity_available', '>', 0)->count(),
            'low_stock_products_count' => ProductInventory::where('location_id', $location->id)
                ->whereRaw('quantity_available <= COALESCE(location_reorder_level, (SELECT reorder_level FROM products WHERE products.id = product_inventory.product_id))')
                ->count(),
            'out_of_stock_products_count' => ProductInventory::where('location_id', $location->id)
                ->where('quantity_available', '<=', 0)->count(),
            'total_inventory_value' => ProductInventory::where('location_id', $location->id)
                ->sum('total_value'),
        ];

        return response()->json($summary);
    }

    /**
     * Search products for API endpoints
     */
    public function searchProducts(Request $request)
    {
        $query = $request->get('q', '');
        $locationId = $request->get('location_id');

        if (empty($query)) {
            return response()->json([]);
        }

        $location = $locationId
            ? InventoryLocation::findOrFail($locationId)
            : InventoryLocation::getDefault();

        $products = ProductInventory::with(['product'])
            ->where('location_id', $location->id)
            ->whereHas('product', function ($q) use ($query) {
                $q->search($query);
            })
            ->limit(10)
            ->get()
            ->map(function ($inventory) {
                return [
                    'id' => $inventory->product->id,
                    'name' => $inventory->product->name,
                    'sku' => $inventory->product->SKU,
                    'barcode' => $inventory->product->barcode,
                    'quantity_available' => $inventory->quantity_available,
                    'unit_cost' => $inventory->unit_cost,
                    'location_id' => $inventory->location_id,
                ];
            });

        return response()->json($products);
    }

    /**
     * Search inventory movements for API endpoints
     */
    public function searchMovements(Request $request)
    {
        $query = $request->get('q', '');
        $locationId = $request->get('location_id');

        if (empty($query)) {
            return response()->json([]);
        }

        $movementsQuery = InventoryMovement::with(['product', 'location', 'user'])
            ->search($query);

        if ($locationId) {
            $movementsQuery->where('location_id', $locationId);
        }

        $movements = $movementsQuery
            ->limit(10)
            ->latest()
            ->get()
            ->map(function ($movement) {
                return [
                    'id' => $movement->id,
                    'product_name' => $movement->product->name ?? 'N/A',
                    'movement_type' => $movement->movement_type,
                    'quantity_change' => $movement->quantity_change,
                    'reference_type' => $movement->reference_type,
                    'notes' => $movement->notes,
                    'created_at' => $movement->created_at,
                ];
            });

        return response()->json($movements);
    }
}
