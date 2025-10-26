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
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventoryService) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $user = auth()->user();

        // Apply role-based location filtering
        $effectiveLocationId = $user->getEffectiveLocationId($request->input('location_id'));
        
        $location = $effectiveLocationId
            ? InventoryLocation::forDomain($slug)->findOrFail($effectiveLocationId)
            : (InventoryLocation::active()->forDomain($slug)->where('is_default', true)->first() 
               ?? InventoryLocation::active()->forDomain($slug)->first() 
               ?? InventoryLocation::getDefault());

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
        $user = auth()->user();

        // Apply role-based location filtering
        $effectiveLocationId = $user->getEffectiveLocationId($request->input('location_id'));
        
        $location = $effectiveLocationId
            ? InventoryLocation::forDomain($slug)->findOrFail($effectiveLocationId)
            : (InventoryLocation::active()->forDomain($slug)->where('is_default', true)->first() 
               ?? InventoryLocation::active()->forDomain($slug)->first() 
               ?? InventoryLocation::getDefault());

        $query = ProductInventory::with(['product', 'location'])
            ->where('location_id', $location->id)
            ->whereHas('product', function($q) use ($slug) {
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
            'categories' => \App\Models\Category::where('domain', $slug)
                ->whereHas('products.inventories', function($query) use ($location) {
                    $query->where('location_id', $location->id);
                })
                ->get(),
            'filters' => $request->only(['search', 'stock_status', 'category_id']),
            'isGlobalView' => false,
        ]);
    }

    public function movements(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $user = auth()->user();

        // Apply role-based location filtering
        $effectiveLocationId = $user->getEffectiveLocationId($request->input('location_id'));
        
        $location = $effectiveLocationId
            ? InventoryLocation::forDomain($slug)->findOrFail($effectiveLocationId)
            : (InventoryLocation::active()->forDomain($slug)->where('is_default', true)->first() 
               ?? InventoryLocation::active()->forDomain($slug)->first() 
               ?? InventoryLocation::getDefault());

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
        $slug = $domain->name_slug;
        $location = $request->location_id
            ? InventoryLocation::forDomain($slug)->findOrFail($request->location_id)
            : null;

        $lowStockProducts = $this->inventoryService->getLowStockProducts($location);

        return response()->json([
            'products' => $lowStockProducts->map(function ($product) use ($location) {
                $inventory = $location ? $product->inventoryAt($location) : $product->defaultInventory();
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

    public function valuation(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $user = auth()->user();

        // Apply role-based location filtering
        $effectiveLocationId = $user->getEffectiveLocationId($request->input('location_id'));
        
        $location = $effectiveLocationId
            ? InventoryLocation::forDomain($slug)->findOrFail($effectiveLocationId)
            : (InventoryLocation::active()->forDomain($slug)->where('is_default', true)->first() 
               ?? InventoryLocation::active()->forDomain($slug)->first() 
               ?? InventoryLocation::getDefault());

        $inventories = ProductInventory::with('product')
            ->where('location_id', $location->id)
            ->where('quantity_on_hand', '>', 0)
            ->whereHas('product', function($q) use ($slug) {
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
}
