<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Support\FinanceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Tables setup: turn dine-in on for a store, add its tables (name, area, seats) and place them on
 * a simple floor plan, which is what the POS tables screen shows. A manager sets up their own
 * store; admins any store.
 */
class DiningTableController extends Controller
{
    /** The floor plan grid, in cells. */
    public const GRID_COLUMNS = 12;

    public const GRID_ROWS = 8;

    public function index(Request $request, Domain $domain)
    {
        $stores = $this->stores($request, $domain);
        $requested = (int) $request->query('location_id');
        $location = $stores->firstWhere('id', $requested)
            ?? $stores->firstWhere('id', Helpers::getActiveLocation($domain)?->id)
            ?? $stores->first();

        $tables = $location
            ? DiningTable::query()->forLocation($location->id)->orderBy('area')->orderBy('name')->get()
            : collect();
        $busy = $location
            ? Sale::query()->openTabs()->where('location_id', $location->id)->whereNotNull('dining_table_id')->pluck('dining_table_id')->unique()->values()
            : collect();

        return Inertia::render('DiningTables/Index', [
            'stores' => $stores->map(fn (InventoryLocation $l) => ['id' => $l->id, 'name' => $l->name, 'dine_in' => (bool) $l->dine_in])->values(),
            'location' => $location ? [
                'id' => $location->id,
                'name' => $location->name,
                'dine_in' => (bool) $location->dine_in,
                'table_cleaning' => (bool) $location->table_cleaning,
            ] : null,
            'tables' => $tables->map(fn (DiningTable $t) => [
                ...$t->only(['id', 'name', 'area', 'seats', 'pos_x', 'pos_y', 'is_active']),
                'busy' => $busy->contains($t->id),
                'cleaning' => ! $busy->contains($t->id) && $t->needs_cleaning_at !== null,
            ])->values(),
            'grid' => ['columns' => self::GRID_COLUMNS, 'rows' => self::GRID_ROWS],
        ]);
    }

    /** "This store serves dine-in": shows the Tables button on its POS. */
    public function settings(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'dine_in' => ['sometimes', 'boolean'],
            // Paid tables wait for staff to mark them ready before the next guests.
            'table_cleaning' => ['sometimes', 'boolean'],
        ]);
        $location = $this->ownStore($request, $domain, (int) $data['location_id']);

        if (array_key_exists('table_cleaning', $data)) {
            $location->update(['table_cleaning' => $data['table_cleaning']]);
            if (! $data['table_cleaning']) {
                DiningTable::query()->forLocation($location->id)->update(['needs_cleaning_at' => null]);
            }
        }
        if (! array_key_exists('dine_in', $data)) {
            return back()->with('success', $data['table_cleaning'] ?? false
                ? "Paid tables at {$location->name} now wait to be marked ready."
                : "Paid tables at {$location->name} are free straight away.");
        }

        if (! $data['dine_in'] && Sale::query()->openTabs()->where('location_id', $location->id)->exists()) {
            throw ValidationException::withMessages(['dine_in' => ['Close the open tabs first (pay or cancel them).']]);
        }
        $location->update(['dine_in' => $data['dine_in']]);

        return back()->with('success', $data['dine_in'] ? "{$location->name} now serves dine-in." : "Dine-in is off at {$location->name}.");
    }

    public function store(Request $request, Domain $domain): RedirectResponse
    {
        $data = $this->validated($request, $domain);
        $location = $this->ownStore($request, $domain, (int) $data['location_id']);
        [$x, $y] = $this->freeCell($location);

        DiningTable::query()->create([
            ...$data,
            'domain' => $domain->name_slug,
            'pos_x' => $x,
            'pos_y' => $y,
        ]);

        return back()->with('success', "Table {$data['name']} added.");
    }

    public function update(Request $request, Domain $domain, DiningTable $diningTable): RedirectResponse
    {
        abort_unless($diningTable->domain === $domain->name_slug, 404);
        $this->ownStore($request, $domain, (int) $diningTable->location_id);
        $data = $this->validated($request, $domain, $diningTable);

        if (! ($data['is_active'] ?? true) && $diningTable->openTabs()->exists()) {
            throw ValidationException::withMessages(['is_active' => ['This table has an open tab. Pay or move it first.']]);
        }
        $diningTable->update(collect($data)->except('location_id')->all());

        return back()->with('success', "Table {$diningTable->name} saved.");
    }

    public function destroy(Request $request, Domain $domain, DiningTable $diningTable): RedirectResponse
    {
        abort_unless($diningTable->domain === $domain->name_slug, 404);
        $this->ownStore($request, $domain, (int) $diningTable->location_id);

        if ($diningTable->openTabs()->exists()) {
            throw ValidationException::withMessages(['table' => ['This table has an open tab. Pay or move it first.']]);
        }
        // Paid tabs keep their sale; they just lose the table's name.
        $diningTable->delete();

        return back()->with('success', 'Table removed.');
    }

    /** The floor plan after dragging: every table's cell. */
    public function layout(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'positions' => ['required', 'array', 'max:500'],
            'positions.*.id' => ['required', 'integer'],
            'positions.*.pos_x' => ['required', 'integer', 'min:0', 'max:'.(self::GRID_COLUMNS - 1)],
            'positions.*.pos_y' => ['required', 'integer', 'min:0', 'max:'.(self::GRID_ROWS - 1)],
        ]);
        $location = $this->ownStore($request, $domain, (int) $data['location_id']);

        $tables = DiningTable::query()->forLocation($location->id)->get()->keyBy('id');
        foreach ($data['positions'] as $position) {
            $tables->get((int) $position['id'])?->update(['pos_x' => $position['pos_x'], 'pos_y' => $position['pos_y']]);
        }

        return back()->with('success', 'Floor plan saved.');
    }

    private function validated(Request $request, Domain $domain, ?DiningTable $table = null): array
    {
        $locationId = $table?->location_id ?? (int) $request->input('location_id');

        return $request->validate([
            'location_id' => $table ? ['nullable'] : ['required', 'integer'],
            'name' => [
                'required', 'string', 'max:30',
                Rule::unique('dining_tables', 'name')->where('location_id', $locationId)->ignore($table?->id),
            ],
            'area' => ['nullable', 'string', 'max:50'],
            'seats' => ['required', 'integer', 'min:1', 'max:99'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'This store already has a table with that name.',
        ]);
    }

    /** The stores this person sets up: a manager their own, admins every store (not warehouses). */
    private function stores(Request $request, Domain $domain)
    {
        $restricted = FinanceAccess::restrictedLocationId($request->user());

        return InventoryLocation::query()->forDomain($domain->name_slug)->active()
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
            ->when($restricted, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')->get();
    }

    private function ownStore(Request $request, Domain $domain, int $locationId): InventoryLocation
    {
        $location = $this->stores($request, $domain)->firstWhere('id', $locationId);
        if (! $location) {
            throw ValidationException::withMessages(['location_id' => ['You can only set up your own store.']]);
        }

        return $location;
    }

    /** The first empty cell, left to right and top to bottom, so a new table never lands on another. */
    private function freeCell(InventoryLocation $location): array
    {
        $taken = DiningTable::query()->forLocation($location->id)->get(['pos_x', 'pos_y'])
            ->map(fn ($t) => $t->pos_x.':'.$t->pos_y)->flip();

        for ($y = 0; $y < self::GRID_ROWS; $y++) {
            for ($x = 0; $x < self::GRID_COLUMNS; $x++) {
                if (! $taken->has($x.':'.$y)) {
                    return [$x, $y];
                }
            }
        }

        return [0, 0];
    }
}
