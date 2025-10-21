<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryLocationRequest;
use App\Http\Requests\UpdateInventoryLocationRequest;
use App\Http\Resources\InventoryLocationResource;
use App\Models\InventoryLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryLocationController extends Controller
{
    /**
     * Display a listing of inventory locations
     */
    public function index(Request $request)
    {
        $query = InventoryLocation::query()
            ->withCount(['productInventories', 'inventoryMovements', 'stockAdjustments'])
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('type'), function ($query, $type) {
                return $query->where('type', $type);
            })
            ->when($request->input('status'), function ($query, $status) {
                if ($status === 'active') {
                    return $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    return $query->where('is_active', false);
                }
                return $query;
            })
            ->when($request->input('domain'), function ($query, $domain) {
                return $query->where('domain', $domain);
            })
            ->orderBy('is_default', 'desc')
            ->orderBy('name');

        $locations = $query->paginate($request->per_page ?? 15);

        // Check if this is an API request
        if ($request->expectsJson() || $request->is('api/*')) {
            return InventoryLocationResource::collection($locations);
        }

        return Inertia::render('Inventory/Locations/Index', [
            'locations' => InventoryLocationResource::collection($locations),
            'filters' => $request->only(['search', 'type', 'status', 'domain']),
            'locationTypes' => $this->getLocationTypes(),
            'domains' => \App\Models\Domain::select('id', 'name', 'name_slug')->get(),
            'isGlobalView' => true,
        ]);
    }

    /**
     * Show the form for creating a new location
     */
    public function create()
    {
        return Inertia::render('Inventory/Locations/Create', [
            'locationTypes' => $this->getLocationTypes(),
        ]);
    }

    /**
     * Store a newly created location
     */
    public function store(StoreInventoryLocationRequest $request)
    {
        // If this is set as default, unset other defaults
        if ($request->is_default) {
            InventoryLocation::where('is_default', true)->update(['is_default' => false]);
        }

        $location = InventoryLocation::create($request->validated());

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return redirect()->route('inventory.locations.index')
            ->with('success', 'Location created successfully.');
    }

    /**
     * Display the specified location
     */
    public function show(Request $request, InventoryLocation $location)
    {
        $location->loadCount(['productInventories', 'inventoryMovements', 'stockAdjustments']);
        
        // Get location statistics
        $stats = [
            'total_products' => $location->productInventories()->count(),
            'in_stock_products' => $location->productInventories()->where('quantity_available', '>', 0)->count(),
            'low_stock_products' => $location->getLowStockProductsCount(),
            'out_of_stock_products' => $location->productInventories()->where('quantity_available', '<=', 0)->count(),
            'total_inventory_value' => $location->getTotalInventoryValue(),
            'recent_movements_count' => $location->inventoryMovements()->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location->load(['productInventories', 'inventoryMovements' => function ($query) {
                $query->latest()->limit(10);
            }]));
        }

        return Inertia::render('Inventory/Locations/Show', [
            'location' => new InventoryLocationResource($location),
            'stats' => $stats,
        ]);
    }

    /**
     * Show the form for editing the specified location
     */
    public function edit(InventoryLocation $location)
    {
        return Inertia::render('Inventory/Locations/Edit', [
            'location' => new InventoryLocationResource($location),
            'locationTypes' => $this->getLocationTypes(),
        ]);
    }

    /**
     * Update the specified location
     */
    public function update(UpdateInventoryLocationRequest $request, InventoryLocation $location)
    {
        // If this is set as default, unset other defaults
        if ($request->is_default && !$location->is_default) {
            InventoryLocation::where('is_default', true)->update(['is_default' => false]);
        }

        $location->update($request->validated());

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return redirect()->route('inventory.locations.index')
            ->with('success', 'Location updated successfully.');
    }

    /**
     * Remove the specified location
     */
    public function destroy(Request $request, InventoryLocation $location)
    {
        // Prevent deletion of default location
        if ($location->is_default) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cannot delete the default location.'], 422);
            }
            return redirect()->back()->withErrors(['error' => 'Cannot delete the default location.']);
        }

        // Check if location has inventory
        if ($location->productInventories()->exists()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cannot delete location with existing inventory.'], 422);
            }
            return redirect()->back()->withErrors(['error' => 'Cannot delete location with existing inventory.']);
        }

        $location->delete();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Location deleted successfully.']);
        }

        return redirect()->route('inventory.locations.index')
            ->with('success', 'Location deleted successfully.');
    }

    /**
     * Search locations for API endpoints
     */
    public function search(Request $request)
    {
        $query = $request->get('q', '');

        if (empty($query)) {
            return response()->json([]);
        }

        $locations = InventoryLocation::search($query)
            ->active()
            ->limit(10)
            ->get()
            ->map(function ($location) {
                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'code' => $location->code,
                    'type' => $location->type,
                    'address' => $location->address,
                ];
            });

        return response()->json($locations);
    }

    /**
     * Set location as default
     */
    public function setDefault(Request $request, InventoryLocation $location)
    {
        // Unset current default
        InventoryLocation::where('is_default', true)->update(['is_default' => false]);
        
        // Set new default
        $location->update(['is_default' => true]);

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return redirect()->back()->with('success', 'Default location updated successfully.');
    }

    /**
     * Toggle location active status
     */
    public function toggleStatus(Request $request, InventoryLocation $location)
    {
        $location->update(['is_active' => !$location->is_active]);

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return redirect()->back()->with('success', 'Location status updated successfully.');
    }

    /**
     * Get available location types
     */
    private function getLocationTypes()
    {
        return [
            ['value' => 'store', 'label' => 'Store'],
            ['value' => 'warehouse', 'label' => 'Warehouse'],
            ['value' => 'supplier', 'label' => 'Supplier'],
            ['value' => 'customer', 'label' => 'Customer'],
        ];
    }
}
