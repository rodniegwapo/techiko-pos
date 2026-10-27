<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\KitchenTicket;
use App\Models\Sale;
use App\Services\TabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dine-in tabs on the POS: the tables screen, opening a tab and picking it up again, moving,
 * merging, splitting and cancelling, and sending new orders to the kitchen. Only at a store that
 * serves dine-in (Tables setup). Adding items and paying go through the usual cart.
 */
class TabController extends Controller
{
    public function __construct(private readonly TabService $tabs) {}

    /** The tables screen: every table with its open tab(s), and the tabs under a name. */
    public function index(Request $request, Domain $domain): JsonResponse
    {
        $location = $this->diningStore($domain);
        $tz = $domain->timezone ?: 'Asia/Manila';

        $open = Sale::query()->forDomain($domain->name_slug)->openTabs()
            ->where('location_id', $location->id)
            ->with(['diningTable:id,name', 'openedBy:id,name'])
            ->withCount('saleItems')
            ->withSum('saleItems as unsent', DB::raw('quantity - sent_quantity'))
            ->orderBy('created_at')
            ->get()
            ->map(fn (Sale $s) => $this->present($s, $tz));

        $tables = DiningTable::query()->forLocation($location->id)->where('is_active', true)
            ->orderBy('area')->orderBy('name')
            ->get(['id', 'name', 'area', 'seats', 'pos_x', 'pos_y'])
            ->map(fn (DiningTable $t) => [
                ...$t->only(['id', 'name', 'area', 'seats', 'pos_x', 'pos_y']),
                'tabs' => $open->where('dining_table_id', $t->id)->values(),
            ]);

        return response()->json([
            'tables' => $tables,
            'tabs' => $open->values(),
            'active_tab_id' => $this->tabs->activeTab($domain, $location)?->id,
        ]);
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $location = $this->diningStore($domain);
        $data = $request->validate([
            'dining_table_id' => ['nullable', 'integer', Rule::exists('dining_tables', 'id')->where('location_id', $location->id)->where('is_active', true)],
            'tab_name' => ['nullable', 'string', 'max:60'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $table = isset($data['dining_table_id']) ? DiningTable::query()->find($data['dining_table_id']) : null;
        $tab = $this->tabs->open($domain, $location, $request->user(), $table, $data['tab_name'] ?? null, $data['guests'] ?? null);
        $this->tabs->select($domain, $tab);

        return response()->json(['tab' => $this->present($tab->load('diningTable'), $this->tz($domain))], 201);
    }

    /** Pick the tab up on this till: the cart now works on it. */
    public function select(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $this->tabs->assertOpenAt($tab, $domain, $this->diningStore($domain));
        $this->tabs->select($domain, $tab);

        return response()->json(['active_tab_id' => $tab->id]);
    }

    /** Back to the tables screen; the cart is the cashier's own again. */
    public function leave(Request $request, Domain $domain): JsonResponse
    {
        $this->tabs->forget($domain);

        return response()->json(['active_tab_id' => null]);
    }

    public function update(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $this->tabs->assertOpenAt($tab, $domain, $this->diningStore($domain));
        $data = $request->validate([
            'tab_name' => ['nullable', 'string', 'max:60'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        if (! $tab->dining_table_id && ! filled($data['tab_name'] ?? null)) {
            throw ValidationException::withMessages(['tab_name' => ['A tab without a table needs a name.']]);
        }
        $tab->update(['tab_name' => filled($data['tab_name'] ?? null) ? trim($data['tab_name']) : null, 'guests' => $data['guests'] ?? null]);

        return response()->json(['tab' => $this->present($tab->fresh('diningTable'), $this->tz($domain))]);
    }

    public function move(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $location = $this->diningStore($domain);
        $this->tabs->assertOpenAt($tab, $domain, $location);
        $data = $request->validate([
            'dining_table_id' => ['nullable', 'integer', Rule::exists('dining_tables', 'id')->where('location_id', $location->id)->where('is_active', true)],
        ]);

        $table = isset($data['dining_table_id']) ? DiningTable::query()->find($data['dining_table_id']) : null;
        $tab = $this->tabs->move($tab, $table);

        return response()->json(['tab' => $this->present($tab->load('diningTable'), $this->tz($domain))]);
    }

    /** This tab's lines go onto another tab, which the till then works on. */
    public function merge(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $location = $this->diningStore($domain);
        $this->tabs->assertOpenAt($tab, $domain, $location);
        $data = $request->validate(['into_sale_id' => ['required', 'integer']]);
        $into = Sale::query()->findOrFail($data['into_sale_id']);
        $this->tabs->assertOpenAt($into, $domain, $location);

        $merged = $this->tabs->merge($tab, $into);
        $this->tabs->select($domain, $merged);

        return response()->json(['tab' => $this->present($merged->load('diningTable'), $this->tz($domain))]);
    }

    public function split(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $this->tabs->assertOpenAt($tab, $domain, $this->diningStore($domain));
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sale_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:99999'],
            'tab_name' => ['nullable', 'string', 'max:60'],
        ]);

        $new = $this->tabs->split($tab, $data['lines'], $data['tab_name'] ?? null, $request->user());

        return response()->json(['tab' => $this->present($new->load('diningTable'), $this->tz($domain))], 201);
    }

    public function cancel(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $this->tabs->assertOpenAt($tab, $domain, $this->diningStore($domain));
        $data = $request->validate([
            'pin' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->tabs->cancel($tab, $request->user(), $data['pin'] ?? null, $data['reason'] ?? null);
        $this->tabs->forget($domain);

        return response()->json(['cancelled' => true]);
    }

    /** What is new on the tab goes to the kitchen; the ticket comes back to be printed. */
    public function send(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $this->tabs->assertOpenAt($tab, $domain, $this->diningStore($domain));

        $ticket = $this->tabs->sendToKitchen($tab, $request->user());
        if (! $ticket) {
            throw ValidationException::withMessages(['tab' => ['Nothing new to send: everything on this tab is already in the kitchen.']]);
        }

        return response()->json(['ticket' => $ticket->toPrint($this->tz($domain))], 201);
    }

    /** Earlier tickets of the tab, to print one again. */
    public function tickets(Request $request, Domain $domain, Sale $tab): JsonResponse
    {
        $this->tabs->assertOpenAt($tab, $domain, $this->diningStore($domain));
        $tz = $this->tz($domain);

        return response()->json([
            'tickets' => $tab->kitchenTickets()->with(['items', 'sender:id,name'])->get()
                ->map(fn (KitchenTicket $t) => $t->toPrint($tz))->values(),
        ]);
    }

    /** The store the till is in, which must serve dine-in. */
    private function diningStore(Domain $domain): InventoryLocation
    {
        $location = Helpers::getActiveLocation($domain);
        if (! $location || $location->domain !== $domain->name_slug) {
            throw ValidationException::withMessages(['location_id' => ['Pick a store first.']]);
        }
        if (! $location->dine_in) {
            throw ValidationException::withMessages(['location_id' => ["{$location->name} doesn't serve dine-in. A manager can turn it on under Tables."]]);
        }

        return $location;
    }

    private function present(Sale $tab, string $tz): array
    {
        $opened = $tab->created_at?->copy()->timezone($tz);

        return [
            'id' => $tab->id,
            'label' => $tab->tabLabel(),
            'dining_table_id' => $tab->dining_table_id,
            'table' => $tab->diningTable?->name,
            'tab_name' => $tab->tab_name,
            'guests' => $tab->guests,
            'total' => round((float) $tab->grand_total, 2),
            'item_count' => (int) ($tab->sale_items_count ?? $tab->saleItems()->count()),
            // Lines added since the last kitchen ticket.
            'unsent' => round((float) ($tab->unsent ?? 0), 3) > 0,
            'opened_at' => $opened?->format('g:i A'),
            'minutes_open' => $tab->created_at ? (int) $tab->created_at->diffInMinutes(now()) : 0,
            'opened_by' => $tab->openedBy?->name,
        ];
    }

    private function tz(Domain $domain): string
    {
        return $domain->timezone ?: 'Asia/Manila';
    }
}
