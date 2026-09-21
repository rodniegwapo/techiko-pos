<?php

namespace App\Http\Controllers\Domains\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\ProductInventoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Services\InventoryService;
use App\Traits\LocationCategoryScoping;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InventoryController extends Controller
{
    use LocationCategoryScoping;

    public function __construct(private InventoryService $inventoryService) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        $report = $this->inventoryService->getInventoryReport($location, $slug);

        return Inertia::render('Inventory/Index', [
            'report' => $report,
            'locations' => InventoryLocation::active()->forDomain($slug)->get(),
            'isGlobalView' => false,
            'current_location' => $location,
        ]);
    }

    public function products(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        if (! $location) {
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
            $query->whereHas('product', fn ($q) => $q->search($request->search));
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
            $query->whereHas('product', fn ($q) => $q->where('category_id', $request->category_id));
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
            'current_location' => $location,
        ]);
    }

    public function movements(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        if ($request->input('export') === 'csv') {
            return $this->exportMovementsCsv($request, $domain, $location);
        }

        // 1–100 per page; anything else (missing, 0, "abc") falls back to 50.
        $perPage = filter_var($request->input('per_page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 50;
        $perPage = min($perPage, 100);

        $movements = $location
            ? $this->movementsQuery($request, $slug, $location)->paginate($perPage)
            : new LengthAwarePaginator([], 0, $perPage);

        return Inertia::render('Inventory/Movements', [
            'movements' => InventoryMovementResource::collection($movements),
            'locations' => InventoryLocation::active()->forDomain($slug)->get(),
            // No products: the page offers no product filter, so sending the organization's whole
            // catalogue only to throw it away cost a table dump on every load and every page turn,
            // growing with the catalogue. Send it again if a product filter is ever added.
            // Only the global view filters by organization.
            'domains' => [],
            'movementTypes' => self::MOVEMENT_TYPES,
            'filters' => $request->only(['search', 'location_id', 'product_id', 'movement_type', 'date_from', 'date_to']),
            'isGlobalView' => false,
            'currentLocation' => $location,
        ]);
    }

    private const MOVEMENT_TYPES = [
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
    ];

    /** The store's movements with the page's filters, newest first. */
    private function movementsQuery(Request $request, string $slug, InventoryLocation $location)
    {
        $query = InventoryMovement::query()
            ->where('domain', $slug)
            ->where('location_id', $location->id)
            ->with(['product.category', 'location', 'user']);

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

        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    /** CSV of the store's movements with the page's current filters (Export button). */
    private function exportMovementsCsv(Request $request, Domain $domain, ?InventoryLocation $location)
    {
        $filename = sprintf('inventory-movements-%s-%s.csv', $location?->code ?? $domain->name_slug, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($request, $domain, $location) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Store', 'Product', 'SKU', 'Type', 'Quantity Before', 'Change', 'Quantity After', 'Unit Cost', 'Total Cost', 'Batch', 'Expiry Date', 'Reference', 'Reason', 'Notes', 'User']);

            if ($location) {
                $this->movementsQuery($request, $domain->name_slug, $location)->chunk(500, function ($movements) use ($out) {
                    foreach ($movements as $m) {
                        fputcsv($out, [
                            $m->created_at?->toDateTimeString(),
                            $m->location?->name,
                            $m->product?->name,
                            $m->product?->SKU,
                            $m->movement_type_display,
                            $m->quantity_before,
                            $m->quantity_change,
                            $m->quantity_after,
                            $m->unit_cost,
                            $m->total_cost,
                            $m->batch_number,
                            $m->expiry_date?->toDateString(),
                            trim(($m->reference_type ?? '').' '.($m->reference_id ? '#'.$m->reference_id : '')),
                            $m->reason,
                            $m->notes,
                            $m->user?->name,
                        ]);
                    }
                });
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        if (! $location) {
            return Inertia::render('Inventory/Valuation', [
                'location' => null,
                'summary' => [
                    'total_value' => 0,
                    'total_quantity' => 0,
                    'total_products' => 0,
                ],
                'items' => [],
                'locations' => InventoryLocation::active()->forDomain($slug)->get(),
                'filters' => $request->only(['location_id']),
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

        // Every row is stock at $location, so its organization is known without loading it per row.
        $valuationData = $inventories->map(function ($inventory) use ($location) {
            return [
                'product_id' => $inventory->product_id,
                'product_name' => $inventory->product->name,
                'sku' => $inventory->product->SKU,
                'quantity_on_hand' => $inventory->quantity_on_hand,
                'average_cost' => $inventory->average_cost,
                'total_value' => $inventory->total_value,
                'last_movement_at' => $inventory->last_movement_at,
                'domain' => $location->domain ?? 'N/A',
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
        $validReferenceTypes = InventoryMovement::getValidReferenceTypes();

        $validated = $request->validate([
            'location_id' => 'required|exists:inventory_locations,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.batch_number' => 'nullable|string|max:255',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.notes' => 'nullable|string|max:500',
            'reference_type' => ['nullable', 'string', Rule::in($validReferenceTypes)],
            'reference_id' => 'nullable|integer',
        ]);

        $location = InventoryLocation::forDomain($domain->name_slug)->findOrFail($validated['location_id']);

        $referenceType = $validated['reference_type'] ?? 'Purchase';
        $referenceId = $validated['reference_id'] ?? null;

        $this->inventoryService->receiveInventory(
            $validated['items'],
            auth()->user(),
            $location,
            $referenceType,
            $referenceId
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

        try {
            $this->inventoryService->transferInventory(
                $product,
                $fromLocation,
                $toLocation,
                $validated['quantity'],
                auth()->user(),
                $validated['notes'] ?? null
            );
        } catch (InsufficientStockException $e) {
            $item = $e->getUnavailableItems()[0] ?? null;
            $available = $item['available_quantity'] ?? 0;
            $message = "Only {$available} units available at source location";

            return response()->json([
                'success' => false,
                'errors' => ['quantity' => [$message]],
                'message' => $message,
            ], 422);
        }

        return response()->noContent(200);
    }

    /**
     * Search products for receiving inventory (domain-specific)
     */
    public function searchProducts(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'location_id' => 'nullable|exists:inventory_locations,id',
            'category_id' => 'nullable|exists:categories,id',
            'scope' => 'nullable|string|in:domain,location',
        ]);

        $location = Helpers::getActiveLocation($domain, $validated['location_id'] ?? null);
        $scope = $validated['scope'] ?? 'location';

        if (! $location) {
            return response()->json([
                'success' => true,
                'data' => ProductResource::collection(collect()),
            ]);
        }

        $query = Product::query()
            ->where('domain', $domain->name_slug)
            ->with([
                'category',
                'inventories' => fn ($q) => $q->where('location_id', $location->id),
            ])
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->search($search))
            ->when($validated['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId));

        if ($scope === 'domain') {
            $query->withExists([
                'activeLocations as at_location' => function ($q) use ($location) {
                    $q->where('inventory_locations.id', $location->id);
                },
            ]);
        } else {
            $query->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('inventory_locations.id', $location->id);
            });
        }

        $products = $query->limit(20)->get();

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products),
        ]);
    }
}
