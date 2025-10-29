<?php

namespace App\Http\Controllers\Domains\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\ProductInventoryResource;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Services\InventoryService;
use App\Helpers;
use App\Models\Category;
use App\Traits\LocationCategoryScoping;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    use LocationCategoryScoping;

    public function __construct(private InventoryService $inventoryService) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $location = Helpers::getActiveLocation($domain);

        $report = $this->inventoryService->getInventoryReport($location, $slug);

        return Inertia::render('Inventory/Index', [
            'report' => $report,
            'locations' => InventoryLocation::active()->forDomain($slug)->get(),
            'isGlobalView' => false,
        ]);
    }

    public function products(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $location = Helpers::getActiveLocation($domain);

        if (!$location) {
            return Inertia::render('Inventory/Products', [
                'inventories' => [],
                'locations' => InventoryLocation::active()->forDomain($slug)->get(),
                'categories' => Category::where('domain', $slug)->get(),
                'filters' => $request->only(['search', 'stock_status', 'category_id']),
                'isGlobalView' => false,
                'current_location' => null,
            ]);
        }

        $query = ProductInventory::with(['product', 'location'])
            ->where('location_id', $location->id)
            ->whereHas('product', function ($q) use ($slug) {
                $q->where('domain', $slug);
            });

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

        $inventories = $query->orderBy('quantity_available', 'asc')
            ->paginate($request->per_page ?? 20);

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

        $inventories->getCollection()->transform(function ($inventory) {
            $inventory->location_stock_status = $inventory->getStockStatus();
            return $inventory;
        });

        return Inertia::render('Inventory/Products', [
            'inventories' => ProductInventoryResource::collection($inventories),
            'locations' => InventoryLocation::active()->forDomain($slug)->get(),
            'categories' => $this->getCategoriesForLocation($slug, $location)->get(),
            'filters' => $request->only(['search', 'stock_status', 'category_id']),
            'isGlobalView' => false,
        ]);
    }

    public function movements(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        if (!$location) {
            return Inertia::render('Inventory/Movements', [
                'movements' => [],
                'locations' => InventoryLocation::active()->forDomain($slug)->get(),
                'filters' => $request->only(['search', 'movement_type', 'date_from', 'date_to']),
                'isGlobalView' => false,
                'current_location' => null,
            ]);
        }

        $query = InventoryMovement::query()
            ->where('domain', $slug)
            ->where('location_id', $location->id)
            ->with(['product', 'location', 'user']);

        if ($request->search) {
            $query->search($request->search);
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

        $movements = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 50);

        return Inertia::render('Inventory/Movements', [
            'movements' => InventoryMovementResource::collection($movements),
            'locations' => InventoryLocation::active()->forDomain($slug)->get(),
            'products' => Product::select('id', 'name', 'SKU')->where('domain', $slug)->get(),
            'domains' => Domain::select('id', 'name', 'name_slug')->get(),
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
            'filters' => $request->only(['search', 'location_id', 'product_id', 'movement_type', 'date_from', 'date_to']),
            'isGlobalView' => false,
        ]);
    }

    public function lowStock(Request $request, Domain $domain)
    {
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        $lowStockProducts = $this->inventoryService->getLowStockProducts($location, $domain->name_slug);

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

    public function valuation(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $location = Helpers::getActiveLocation($domain);

        if (!$location) {
            return response()->json([
                'total_value' => 0,
                'total_quantity' => 0,
                'inventories' => [],
            ]);
        }

        $inventories = ProductInventory::with('product')
            ->where('location_id', $location->id)
            ->where('quantity_on_hand', '>', 0)
            ->whereHas('product', function ($q) use ($slug) {
                $q->where('domain', $slug);
            })
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
                'domain' => $inventory->location->domain ?? 'N/A',
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
            'locations' => InventoryLocation::active()->forDomain($slug)->get(),
            'filters' => $request->only(['location_id']),
        ]);
    }

    public function receive(Request $request, Domain $domain)
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

        $location = InventoryLocation::forDomain($domain->name_slug)->findOrFail($validated['location_id']);

        $this->inventoryService->receiveInventory(
            $validated['items'],
            auth()->user(),
            $location,
            $validated['reference_type'] ?? null,
            $validated['reference_id'] ?? null
        );

        return response()->json(['success' => true, 'message' => 'Inventory received successfully']);
    }

    public function transfer(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'from_location_id' => 'required|exists:inventory_locations,id',
            'to_location_id' => 'required|exists:inventory_locations,id|different:from_location_id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        $product = Product::where('domain', $domain->name_slug)->findOrFail($validated['product_id']);
        $fromLocation = InventoryLocation::forDomain($domain->name_slug)->findOrFail($validated['from_location_id']);
        $toLocation = InventoryLocation::forDomain($domain->name_slug)->findOrFail($validated['to_location_id']);

        $this->inventoryService->transferInventory(
            $product,
            $fromLocation,
            $toLocation,
            $validated['quantity'],
            auth()->user(),
            $validated['notes'] ?? null
        );

        return response()->noContent(200);
    }

    /**
     * Search products for receiving inventory (domain-specific)
     */
    public function searchProducts(Request $request, Domain $domain)
    {
        $location = Helpers::getActiveLocation($domain);

        $query = Product::query()
            ->where('domain', $domain->name_slug)
            ->with('category')
            ->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('location_id', $location->id);
            })
            ->when($request->search, fn($q, $search) => $q->search($search))
            ->when($request->category_id, fn($q, $categoryId) => $q->where('category_id', $categoryId));

        $products = $query->limit(20)->get();

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ProductResource::collection($products)
        ]);
    }
}
