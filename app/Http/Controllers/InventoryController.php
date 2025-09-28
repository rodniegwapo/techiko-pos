<?php

namespace App\Http\Controllers;

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
        $this->inventoryService = $inventoryService;
    }

    /**
     * Display inventory overview
     */
    public function index(Request $request)
    {
        $location = null;
        if ($request->location_id) {
            $location = InventoryLocation::findOrFail($request->location_id);
        }

        $report = $this->inventoryService->getInventoryReport($location);

        return Inertia::render('Inventory/Index', [
            'report' => $report,
            'locations' => InventoryLocation::active()->get(),
            'currentLocation' => $location,
        ]);
    }

    /**
     * Display inventory levels for products
     */
    public function products(Request $request)
    {
        $location = $request->location_id 
            ? InventoryLocation::findOrFail($request->location_id)
            : InventoryLocation::getDefault();

        $query = ProductInventory::with(['product', 'location'])
            ->where('location_id', $location->id);

        // Apply filters
        if ($request->search) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('SKU', 'like', "%{$request->search}%")
                  ->orWhere('barcode', 'like', "%{$request->search}%");
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
            'inventories' => $inventories,
            'locations' => InventoryLocation::active()->get(),
            'currentLocation' => $location,
            'categories' => \App\Models\Category::all(),
            'filters' => $request->only(['search', 'stock_status', 'category_id']),
        ]);
    }

    /**
     * Display inventory movements/history
     */
    public function movements(Request $request)
    {
        $query = InventoryMovement::with(['product', 'location', 'user'])
            ->orderBy('created_at', 'desc');

        // Apply filters
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

        $movements = $query->paginate($request->per_page ?? 50);

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'locations' => InventoryLocation::active()->get(),
            'products' => Product::select('id', 'name', 'SKU')->get(),
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
            'filters' => $request->only(['location_id', 'product_id', 'movement_type', 'date_from', 'date_to']),
        ]);
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
    public function lowStock(Request $request)
    {
        $location = $request->location_id 
            ? InventoryLocation::findOrFail($request->location_id)
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
    public function valuation(Request $request)
    {
        $location = $request->location_id 
            ? InventoryLocation::findOrFail($request->location_id)
            : InventoryLocation::getDefault();

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

        return response()->json([
            'location' => $location,
            'summary' => [
                'total_value' => $totalValue,
                'total_quantity' => $totalQuantity,
                'total_products' => $inventories->count(),
            ],
            'items' => $valuationData,
        ]);
    }
}