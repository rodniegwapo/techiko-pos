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
            ->when(! $request->user()->isSuperUser(), fn ($q) => $q->where('domain', $request->user()->domain))
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
            'domains' => \App\Models\Domain::select('id', 'name', 'name_slug')->get(),
            'isGlobalView' => true,
        ]);
    }

    /**
     * Store a newly created location
     */
    public function store(StoreInventoryLocationRequest $request)
    {
        $data = $request->validated();
        // Only super users may create stores for another organization.
        if (! $request->user()->isSuperUser()) {
            $data['domain'] = $request->user()->domain;
        }
        $makeDefault = (bool) ($data['is_default'] ?? false);
        unset($data['is_default']);

        $location = InventoryLocation::create($data);
        // setAsDefault only replaces the default within this store's organization.
        if ($makeDefault) {
            $location->setAsDefault();
        }

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
        $this->authorizeLocation($request, $location);

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
    public function edit(Request $request, InventoryLocation $location)
    {
        $this->authorizeLocation($request, $location);

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
        $this->authorizeLocation($request, $location);

        $data = $request->validated();
        if (! $request->user()->isSuperUser()) {
            unset($data['domain']);
        }
        $makeDefault = (bool) ($data['is_default'] ?? false);
        // Unsetting the default happens by making another store the default.
        unset($data['is_default']);

        $location->update($data);
        if ($makeDefault) {
            $location->setAsDefault();
        }

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
        $this->authorizeLocation($request, $location);

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
            ->when(! $request->user()->isSuperUser(), fn ($q) => $q->where('domain', $request->user()->domain))
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
     * Set location as default for its domain
     */
    public function setDefault(Request $request, InventoryLocation $location)
    {
        $this->authorizeLocation($request, $location);

        // Use the new domain-specific method
        $location->setAsDefault();

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return redirect()->back()->with('success', 'Default location updated successfully for this domain.');
    }

    /**
     * Toggle location active status
     */
    public function toggleStatus(Request $request, InventoryLocation $location)
    {
        $this->authorizeLocation($request, $location);

        $location->update(['is_active' => !$location->is_active]);

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return redirect()->back()->with('success', 'Location status updated successfully.');
    }

    /**
     * These routes aren't tied to an organization, so anyone but a super user may only touch their
     * own organization's stores (otherwise an admin could change another organization's stores).
     */
    private function authorizeLocation(Request $request, InventoryLocation $location): void
    {
        $user = $request->user();
        if (! $user->isSuperUser() && $location->domain !== $user->domain) {
            abort(403, 'Location does not belong to your organization.');
        }
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
