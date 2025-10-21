<?php

namespace App\Http\Controllers\Domains\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryLocationResource;
use App\Models\Domain;
use App\Models\InventoryLocation;
use Illuminate\Http\Request;

class InventoryLocationController extends Controller
{
    public function index(Request $request, Domain $domain)
    {
        $items = InventoryLocation::active()
            ->forDomain($domain->name_slug)
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->orderBy('name')
            ->paginate($request->per_page ?? 15);

        $locationTypes = [
            ['label' => 'Store', 'value' => 'store'],
            ['label' => 'Warehouse', 'value' => 'warehouse'],
            ['label' => 'Supplier', 'value' => 'supplier'],
            ['label' => 'Customer', 'value' => 'customer'],
        ];

        return inertia('Inventory/Locations/Index', [
            'locations' => InventoryLocationResource::collection($items),
            'filters' => $request->only(['search', 'type', 'status']),
            'locationTypes' => $locationTypes,
            'currentDomain' => $domain,
            'isGlobalView' => false,
        ]);
    }

    public function store(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:inventory_locations,code',
            'type' => 'required|in:store,warehouse,supplier,customer',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $validated['domain'] = $domain->name_slug;
        $location = InventoryLocation::create($validated);

        return back()->with('success', 'Location created');
    }

    public function update(Request $request, Domain $domain, InventoryLocation $location)
    {
        if ($location->domain !== $domain->name_slug) {
            abort(403, 'Location does not belong to this domain');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:store,warehouse,supplier,customer',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $location->update($validated);

        return back()->with('success', 'Location updated');
    }

    public function destroy(Domain $domain, InventoryLocation $location)
    {
        if ($location->domain !== $domain->name_slug) {
            abort(403, 'Location does not belong to this domain');
        }

        $location->delete();
        return back()->with('success', 'Location deleted');
    }
}


