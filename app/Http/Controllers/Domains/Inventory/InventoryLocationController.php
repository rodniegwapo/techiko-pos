<?php

namespace App\Http\Controllers\Domains\Inventory;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryLocationResource;
use App\Http\Requests\InventoryLocationRequest;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Traits\LocationTypes;
use Illuminate\Http\Request;

class InventoryLocationController extends Controller
{
    use LocationTypes;

    /** 1–100 per page; anything else (missing, 0, "abc") falls back to 15. */
    private function perPage(Request $request): int
    {
        $perPage = filter_var($request->input('per_page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 15;

        return min($perPage, 100);
    }

    public function index(Request $request, Domain $domain)
    {
        $user = auth()->user();

        // Every status is listed, so a deactivated store can still be found and switched back on.
        $query = InventoryLocation::query()
            ->forDomain($domain->name_slug)
            ->withCount('productInventories')
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->when($request->input('type'), fn($q, $type) => $q->where('type', $type))
            ->when($request->input('status'), function ($q, $status) {
                return match ($status) {
                    'active' => $q->where('is_active', true),
                    'inactive' => $q->where('is_active', false),
                    default => $q,
                };
            })
            ->orderBy('is_default', 'desc')
            ->orderBy('name');

        // Apply role-based access control
        if ($user->hasLocationRestriction() && $user->location_id) {
            $query->where('id', $user->location_id);
        }

        $items = $query->paginate($this->perPage($request));

        return inertia('Inventory/Locations/Index', [
            'locations' => InventoryLocationResource::collection($items),
            'filters' => $request->only(['search', 'type', 'status']),
            'locationTypes' => $this->getLocationTypes(),
            'currentDomain' => $domain,
            'isGlobalView' => false,
        ]);
    }

    /**
     * Show the form for creating a new location within a domain
     */
    public function create(Request $request, Domain $domain)
    {
        return inertia('Inventory/Locations/Create', [
            'locationTypes' => $this->getLocationTypes(),
            'currentDomain' => $domain,
            'isGlobalView' => false,
        ]);
    }

    public function store(InventoryLocationRequest $request, Domain $domain)
    {
        $validated = $request->validated();
        $validated['domain'] = $domain->name_slug;
        $makeDefault = (bool) ($validated['is_default'] ?? false);
        unset($validated['is_default']);

        $location = InventoryLocation::create($validated);
        // Through setAsDefault so the domain keeps exactly one default store.
        if ($makeDefault) {
            $location->setAsDefault();
        }

        return back()->with('success', 'Location created');
    }

    /**
     * Display the specified location within a domain
     */
    public function show(Request $request, Domain $domain, InventoryLocation $location)
    {
        $this->ensureLocationBelongsToDomain($location, $domain);

        $location->loadCount(['productInventories', 'inventoryMovements', 'stockAdjustments']);

        $stats = [
            'total_products' => $location->productInventories()->count(),
            'in_stock_products' => $location->productInventories()->where('quantity_available', '>', 0)->count(),
            'low_stock_products' => $location->getLowStockProductsCount(),
            'out_of_stock_products' => $location->productInventories()->where('quantity_available', '<=', 0)->count(),
            'total_inventory_value' => $location->getTotalInventoryValue(),
            'recent_movements_count' => $location->inventoryMovements()->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return inertia('Inventory/Locations/Show', [
            'location' => new InventoryLocationResource($location),
            'stats' => $stats,
        ]);
    }

    public function update(InventoryLocationRequest $request, Domain $domain, InventoryLocation $location)
    {
        $this->ensureLocationBelongsToDomain($location, $domain);

        $validated = $request->validated();
        $makeDefault = (bool) ($validated['is_default'] ?? false);
        // Unsetting the default happens by making another store the default.
        unset($validated['is_default']);

        $location->update($validated);
        if ($makeDefault) {
            $location->setAsDefault();
        }

        return back()->with('success', 'Location updated');
    }

    /**
     * Show the form for editing a location within a domain
     */
    public function edit(Request $request, Domain $domain, InventoryLocation $location)
    {
        $this->ensureLocationBelongsToDomain($location, $domain);

        return inertia('Inventory/Locations/Edit', [
            'location' => new InventoryLocationResource($location),
            'locationTypes' => $this->getLocationTypes(),
        ]);
    }

    public function destroy(Request $request, Domain $domain, InventoryLocation $location)
    {
        $this->ensureLocationBelongsToDomain($location, $domain);

        // The organization would be left without a default store.
        if ($location->is_default) {
            return $this->refuse($request, 'Cannot delete the default location.');
        }

        // Stock and history point at the store, so the database would refuse the delete anyway.
        if ($location->productInventories()->exists() || $location->inventoryMovements()->exists()) {
            return $this->refuse($request, 'Cannot delete a location that still has inventory or movements.');
        }

        $location->delete();

        return back()->with('success', 'Location deleted');
    }

    /** Says no to a JSON caller outright; the page reads the reason from the flash message. */
    private function refuse(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    /**
     * Make this store the organization's default (the store used when nobody picked one).
     */
    public function setDefault(Request $request, Domain $domain, InventoryLocation $location)
    {
        $this->ensureLocationBelongsToDomain($location, $domain);

        $location->setAsDefault();

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return back()->with('success', 'Default location updated successfully for this domain.');
    }

    public function toggleStatus(Request $request, Domain $domain, InventoryLocation $location)
    {
        $this->ensureLocationBelongsToDomain($location, $domain);

        $location->update(['is_active' => ! $location->is_active]);

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return back()->with('success', 'Location status updated successfully.');
    }

    /**
     * Switch the store this admin works in (header location badge). Kept in their session only,
     * so it doesn't change the organization's default store for everyone else.
     */
    public function switch(Request $request, Domain $domain, InventoryLocation $location)
    {
        $this->ensureLocationBelongsToDomain($location, $domain);

        if ($request->user()->hasLocationRestriction()) {
            abort(403, 'You are assigned to a store and can\'t switch.');
        }

        if (! $location->is_active) {
            abort(422, 'This store is inactive.');
        }

        $request->session()->put(Helpers::selectedLocationSessionKey($domain->name_slug), $location->id);

        if ($request->expectsJson()) {
            return new InventoryLocationResource($location);
        }

        return back();
    }

    /**
     * Ensure location belongs to the specified domain
     */
    private function ensureLocationBelongsToDomain(InventoryLocation $location, Domain $domain)
    {
        if ($location->domain !== $domain->name_slug) {
            abort(403, 'Location does not belong to this domain');
        }
    }
}
