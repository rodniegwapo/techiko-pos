<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\ProductInventoryResource;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Services\InventoryService;
use App\Traits\MovementTypes;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    use MovementTypes;

    public function __construct(private InventoryService $inventoryService) {}

    public function index(Request $request)
    {
        $location = $request->location_id
            ? InventoryLocation::active()->findOrFail($request->location_id)
            : null;

        $report = $this->inventoryService->getInventoryReport($location, null);

        return Inertia::render('Inventory/Index', [
            'report' => $report,
            'locations' => InventoryLocation::active()->get(),
            'isGlobalView' => true,
        ]);
    }

    public function products(Request $request)
    {
        $location = $request->location_id
            ? InventoryLocation::active()->findOrFail($request->location_id)
            : null;

        // Eager-load product locations so UI can render availability across stores in global view
        $query = ProductInventory::with(['product.locations', 'location']);

        if ($location) {
            $query->where('location_id', $location->id);
        }

        if ($request->search) {
            $query->whereHas('product', fn($q) => $q->search($request->search));
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
            $query->whereHas('product', fn($q) => $q->where('category_id', $request->category_id));
        }

        // Add domain filtering for global view
        if ($request->domain) {
            $query->whereHas('product', fn($q) => $q->where('domain', $request->domain));
        }

        $inventories = $query->orderBy('quantity_available', 'asc')
            ->paginate($request->per_page ?? 20);

        // API response
        if ($request->expectsJson() || $request->is('api/*')) {
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

        // Web response
        $inventories->getCollection()->transform(function ($inventory) {
            $inventory->location_stock_status = $inventory->getStockStatus();
            return $inventory;
        });

        $categories = Category::query();
        if ($location) {
            $categories->whereHas('products', function ($q) use ($location) {
                $q->whereHas('activeLocations', function ($lq) use ($location) {
                    $lq->where('location_id', $location->id);
                });
            });
        }

        return Inertia::render('Inventory/Products', [
            'inventories' => ProductInventoryResource::collection($inventories),
            'locations' => InventoryLocation::active()->get(),
            'categories' => $categories->get(),
            'domains' => Domain::select('id', 'name', 'name_slug')->get(),
            'filters' => $request->only(['search', 'stock_status', 'category_id', 'domain']),
            'isGlobalView' => true,
        ]);
    }

    public function movements(Request $request)
    {
        $locationId = $request->input('location_id');
        $productId = $request->input('product_id');
        $movementType = $request->input('movement_type');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = InventoryMovement::query()
            ->with(['product', 'location'])
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($productId, fn($q) => $q->where('product_id', $productId))
            ->when($movementType, fn($q) => $q->where('movement_type', $movementType))
            ->when($dateFrom, fn($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->orderByDesc('created_at');

        $movements = $query->paginate($request->per_page ?? 20);

        return Inertia::render('Inventory/Movements', [
            'movements' => InventoryMovementResource::collection($movements),
            'locations' => InventoryLocation::active()->get(),
            'products' => Product::select('id', 'name', 'SKU')->get(),
            'domains' => Domain::select('id', 'name', 'name_slug')->get(),
            'movementTypes' => $this->getMovementTypes(),
            'filters' => $request->only(['search', 'location_id', 'product_id', 'movement_type', 'date_from', 'date_to']),
            'isGlobalView' => true,
        ]);
    }

    public function lowStock(Request $request)
    {
        $location = $request->location_id
            ? InventoryLocation::active()->findOrFail($request->location_id)
            : null;

        $lowStockProducts = $this->inventoryService->getLowStockProducts($location, null);

        return response()->json([
            'products' => $lowStockProducts->map(function ($product) use ($location) {
                $inventory = $location ? $product->inventoryAt($location) : null;
                $reorderLevel = $inventory ? $inventory->getEffectiveReorderLevel() : $product->reorder_level;
                $currentStock = $inventory ? $inventory->quantity_available : 0;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->SKU,
                    'current_stock' => $currentStock,
                    'reorder_level' => $reorderLevel,
                    'shortage' => max(0, $reorderLevel - $currentStock),
                ];
            }),
        ]);
    }

    /**
     * Search products for receiving inventory (global search across domains)
     */
    public function searchProducts(Request $request)
    {
        $query = Product::query()
            ->with('category')
            ->when($request->search, fn($q, $search) => $q->search($search))
            ->when($request->domain, fn($q, $domain) => $q->where('domain', $domain))
            ->when($request->category_id, fn($q, $categoryId) => $q->where('category_id', $categoryId));

        $products = $query->limit(20)->get();

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ProductResource::collection($products)
        ]);
    }

    /**
     * Receive inventory at a location (global method)
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

        $location = InventoryLocation::findOrFail($validated['location_id']);

        $this->inventoryService->receiveInventory(
            $validated['items'],
            auth()->user(),
            $location,
            $validated['reference_type'] ?? null,
            $validated['reference_id'] ?? null
        );

        return response()->json(['success' => true, 'message' => 'Inventory received successfully']);
    }

    /**
     * Transfer inventory between locations (global method)
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

        return response()->json(['success' => true, 'message' => 'Inventory transferred successfully']);
    }
}