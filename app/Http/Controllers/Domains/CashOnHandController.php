<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\CashOnHandMovement;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\RegisterShift;
use App\Support\Wallet\CashOnHand;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cash on hand: where the business's cash is once the owner has it. The owner collects approved
 * shifts here (the one place to collect), moves cash back to a store, and records what they kept for
 * themselves. Owners and admins only (the routes' permissions, and CashOnHand checks again).
 */
class CashOnHandController extends Controller
{
    public function __construct(private CashOnHand $cash) {}

    public function index(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = Carbon::parse($validated['date_from'] ?? now()->subDays(29))->toDateString();
        $to = Carbon::parse($validated['date_to'] ?? now())->toDateString();
        $slug = $domain->name_slug;

        $lines = CashOnHandMovement::query()->forDomain($slug)
            ->whereDate('movement_date', '>=', $from)
            ->whereDate('movement_date', '<=', $to)
            ->with(['location:id,name', 'user:id,name', 'registerShift.user:id,name'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Balance after each line: everything up to the newest line on the page, then back down.
        $top = $lines->first();
        $after = $top ? round((float) CashOnHandMovement::query()->forDomain($slug)->where('id', '<=', $top->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN -amount ELSE amount END), 0) AS b")->value('b'), 2) : 0.0;
        $lines->through(function (CashOnHandMovement $m) use (&$after) {
            $row = [
                'id' => $m->id,
                'date' => $m->movement_date->toDateString(),
                'at' => $m->created_at?->toIso8601String(),
                'kind' => $m->kind,
                'direction' => $m->direction,
                'amount' => (float) $m->amount,
                'store' => $m->location?->name,
                'shift_of' => $m->registerShift?->user?->name,
                'source_date' => $m->source_date?->toDateString(),
                'notes' => $m->notes,
                'by' => $m->user?->name,
                'balance_after' => $after,
            ];
            $after = round($after - $m->signedAmount(), 2);

            return $row;
        });

        $stores = InventoryLocation::query()->forDomain($slug)->active()
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
            ->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Wallet/CashOnHand', [
            'balance' => CashOnHand::balance($slug),
            'waiting' => CashOnHand::waitingToCollect($slug),
            // Today at a glance: what came in from the drawers, and what went back out to the stores.
            'today' => [
                'collected' => CashOnHand::collectedOn($slug, now()->toDateString()),
                'moved_to_stores' => CashOnHand::movedToStoresOn($slug, now()->toDateString()),
            ],
            'lines' => $lines,
            // Where "Move to a store" can go: a store's drawer or one of its payment channels.
            'stores' => $stores->map(fn (InventoryLocation $s) => [
                'id' => (int) $s->id,
                'name' => (string) $s->name,
                'channels' => PaymentCardType::query()->forDomainLocation($slug, (int) $s->id)->active()
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            ])->values(),
            'filters' => ['date_from' => $from, 'date_to' => $to],
        ]);
    }

    /** Move to a store, "Owner used it", or a correction. */
    public function store(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['to_store', 'owner_use', 'adjustment'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'location_id' => ['required_if:action,to_store', 'nullable', 'integer'],
            'payment_card_type_id' => ['nullable', 'integer'],
            'direction' => ['required_if:action,adjustment', 'nullable', Rule::in(['in', 'out'])],
            'notes' => ['required_if:action,adjustment', 'nullable', 'string', 'max:2000'],
        ], [
            'location_id.required_if' => 'Pick the store the cash goes to.',
            'notes.required_if' => 'Say what the correction is for.',
        ]);
        $user = $request->user();
        $amount = (float) $validated['amount'];

        if ($validated['action'] === 'to_store') {
            return redirect()->back()->with('success', $this->toStore($domain, $request, $validated, $amount));
        }
        if ($validated['action'] === 'owner_use') {
            $this->cash->ownerUse($domain, $user, $amount, $validated['notes'] ?? null);

            return redirect()->back()->with('success', 'Recorded as taken by the owner.');
        }
        $this->cash->adjust($domain, $user, (string) $validated['direction'], $amount, (string) $validated['notes']);

        return redirect()->back()->with('success', 'Correction saved.');
    }

    /** The owner collects an approved shift's cash into Cash on hand. */
    public function collect(Request $request, Domain $domain, RegisterShift $registerShift)
    {
        abort_unless($registerShift->domain === $domain->name_slug, 404);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->cash->collectShift($domain, $registerShift, $request->user(), (float) $validated['amount'], $validated['notes'] ?? null);

        return redirect()->back()->with('success', 'Collected ₱'.number_format((float) $validated['amount'], 2).' into Cash on hand.');
    }

    /** Cash on hand into a store's drawer, or one of its payment channels. */
    private function toStore(Domain $domain, Request $request, array $validated, float $amount): string
    {
        $store = InventoryLocation::query()->forDomain($domain->name_slug)->active()->whereKey((int) $validated['location_id'])
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse'))
            ->first()
            ?? throw ValidationException::withMessages(['location_id' => 'Pick a store of this business.']);
        $channel = ! empty($validated['payment_card_type_id'])
            ? (PaymentCardType::query()->forDomainLocation($domain->name_slug, (int) $store->id)->find((int) $validated['payment_card_type_id'])
                ?? throw ValidationException::withMessages(['payment_card_type_id' => 'Pick one of '.$store->name."'s payment channels."]))
            : null;

        $this->cash->toStore($domain, $request->user(), $store, $channel, $amount, $validated['notes'] ?? null);

        return 'Moved to '.($channel ? $channel->name.' ('.$store->name.')' : $store->name."'s drawer").'.';
    }
}
