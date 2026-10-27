<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Wallet\WalletLocationResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PaymentCardTypeController extends Controller
{
    /**
     * The old Money movement page, split into the wallet pages: old links land on the right one.
     */
    public function moneyMovement(Request $request, Domain $domain)
    {
        $query = $request->query();

        if (($query['view'] ?? null) === 'all') {
            unset($query['view']);

            return redirect()->route('domains.wallet.balances', ['domain' => $domain, ...$query, 'tab' => 'all']);
        }

        $ledgerFilters = ['date_from', 'date_to', 'kind', 'rail', 'payment_card_type_id', 'page'];
        $target = array_intersect(array_keys($query), $ledgerFilters) ? 'domains.wallet.cash-in-out' : 'domains.wallet.drawer';

        return redirect()->route($target, ['domain' => $domain, ...$query]);
    }

    /**
     * Payment channels: the store's card terminals, e-wallets and banks.
     */
    public function index(Request $request, Domain $domain)
    {
        return Inertia::render('Wallet/CardTerminals', $this->channelPageProps($request, $domain));
    }

    /**
     * Full-page paid card sales detail for one terminal (Inertia).
     */
    public function details(Request $request, Domain $domain, PaymentCardType $paymentCardType)
    {
        if (! $request->user()->hasPermissionToRoute('payment-card-types.money')) {
            abort(403);
        }

        $location = WalletLocationResolver::resolve($request, $domain);
        $this->ensureInDomainLocation($domain, $location, $paymentCardType);

        $props = $this->channelPageProps($request, $domain);

        $props['moneyDetailsCardType'] = [
            'id' => (int) $paymentCardType->id,
            'name' => (string) $paymentCardType->name,
            'kind' => (string) ($paymentCardType->kind ?? 'card'),
            'is_active' => (bool) $paymentCardType->is_active,
        ];

        return Inertia::render('Wallet/CardTerminalMoneyDetails', $props);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelPageProps(Request $request, Domain $domain): array
    {
        // The day carried over from the other wallet pages; a future one is refused.
        $request->validate(['business_date' => ['sometimes', 'date', 'before_or_equal:today']]);
        $location = WalletLocationResolver::resolve($request, $domain);

        return [
            'cardTypes' => PaymentCardType::query()
                ->forDomainLocation($domain->name_slug, $location->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'walletCreditTotals' => $this->paidSalesTotalsByPaymentMethod($domain, $location, 'credit'),
            'walletEwalletTotals' => $this->paidSalesTotalsByPaymentMethod($domain, $location, 'e-wallet'),
            'walletBankTotals' => $this->paidSalesTotalsByPaymentMethod($domain, $location, 'bank'),
            'activeLocation' => [
                'id' => $location->id,
                'name' => $location->name,
            ],
        ];
    }

    /**
     * Today / yesterday sums for paid sales by payment method (domain-wide), same date rules as money().
     *
     * @return array{today_total: float, yesterday_total: float}
     */
    private function paidSalesTotalsByPaymentMethod(Domain $domain, InventoryLocation $location, string $paymentMethod): array
    {
        $base = Sale::query()
            ->where('domain', $domain->name_slug)
            ->where('location_id', $location->id)
            ->where('payment_status', 'paid');

        // Sales paid this way, and this way's part of sales paid in parts.
        $todayTotal = SalePayment::totalFor(
            (clone $base)->whereDate('transaction_date', now()->toDateString()),
            $paymentMethod
        );

        $yesterdayTotal = SalePayment::totalFor(
            (clone $base)->whereDate('transaction_date', now()->subDay()->toDateString()),
            $paymentMethod
        );

        return [
            'today_total' => (float) $todayTotal,
            'yesterday_total' => (float) $yesterdayTotal,
        ];
    }

    /**
     * JSON list for the Sales channel picker (active channels only; `?kind=` for one kind).
     */
    public function list(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $kind = in_array($request->input('kind'), PaymentCardType::KINDS, true) ? $request->input('kind') : null;

        $types = PaymentCardType::query()
            ->forDomainLocation($domain->name_slug, $location->id)
            ->active()
            ->when($kind, fn ($q) => $q->ofKind($kind))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'is_active']);

        return response()->json(['data' => $types]);
    }

    public function store(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueNameInLocation($domain, $location)],
            'kind' => ['nullable', Rule::in(PaymentCardType::KINDS)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], $this->uniqueNameMessage());

        $type = PaymentCardType::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'kind' => $validated['kind'] ?? 'card',
            'is_active' => true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'data' => $type,
        ], 201);
    }

    public function update(Request $request, Domain $domain, PaymentCardType $paymentCardType)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $this->ensureInDomainLocation($domain, $location, $paymentCardType);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                $this->uniqueNameInLocation($domain, $location)->ignore($paymentCardType->id),
            ],
            'kind' => ['sometimes', Rule::in(PaymentCardType::KINDS)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], $this->uniqueNameMessage());

        $paymentCardType->update($validated);

        return response()->json([
            'success' => true,
            'data' => $paymentCardType->fresh(),
        ]);
    }

    /**
     * Paid sales totals for today / yesterday and paginated transaction history for this card type.
     */
    public function money(Request $request, Domain $domain, PaymentCardType $paymentCardType)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $this->ensureInDomainLocation($domain, $location, $paymentCardType);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'history_from' => ['nullable', 'date', 'before_or_equal:today'],
            'history_to' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $historyFrom = $validated['history_from'] ?? null;
        $historyTo = $validated['history_to'] ?? null;
        if ($historyFrom !== null && $historyTo !== null && $historyFrom > $historyTo) {
            throw ValidationException::withMessages([
                'history_to' => [__('The history end date must be on or after the start date.')],
            ]);
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        // Card sales for a terminal, e-wallet sales for GCash, bank sales for a bank account.
        $method = PaymentCardType::methodForKind($paymentCardType->kind ?? 'card');
        $channelId = $paymentCardType->id;

        $paidHere = Sale::query()
            ->where('domain', $domain->name_slug)
            ->where('location_id', $location->id)
            ->where('payment_status', 'paid');

        $todayTotal = SalePayment::totalFor(
            (clone $paidHere)->whereDate('transaction_date', now()->toDateString()),
            $method,
            $channelId
        );

        $yesterdayTotal = SalePayment::totalFor(
            (clone $paidHere)->whereDate('transaction_date', now()->subDay()->toDateString()),
            $method,
            $channelId
        );

        // Sales paid wholly through this channel, and split sales with a part paid through it.
        $partHere = fn ($q) => $q->where('method', $method)->where('payment_card_type_id', $channelId);
        $historyBase = (clone $paidHere)
            ->where(function ($q) use ($method, $channelId, $partHere) {
                $q->where(fn ($single) => $single->where('payment_method', $method)->where('payment_card_type_id', $channelId))
                    ->orWhere(fn ($split) => $split->where('payment_method', 'split')->whereHas('payments', $partHere));
            })
            ->with(['payments' => $partHere]);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $historyBase->where('invoice_number', 'like', $like);
        }
        if ($historyFrom !== null) {
            $historyBase->whereDate('transaction_date', '>=', $historyFrom);
        }
        if ($historyTo !== null) {
            $historyBase->whereDate('transaction_date', '<=', $historyTo);
        }

        $history = $historyBase
            ->orderByDesc('transaction_date')
            ->paginate($perPage, ['*'], 'page', $page);

        $history->setCollection(
            $history->getCollection()->map(function (Sale $sale) {
                $ts = $sale->transaction_date;
                if ($ts && ! $ts instanceof CarbonInterface) {
                    $ts = Carbon::parse($ts);
                }

                return [
                    'id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    // For a split sale, only what went through this channel.
                    'grand_total' => $sale->payment_method === 'split'
                        ? round((float) $sale->payments->sum('amount'), 2)
                        : (float) $sale->grand_total,
                    'is_split' => $sale->payment_method === 'split',
                    'transaction_date' => $ts ? $ts->toIso8601String() : null,
                ];
            })
        );

        return response()->json([
            'today_total' => (float) $todayTotal,
            'yesterday_total' => (float) $yesterdayTotal,
            'history' => $history,
        ]);
    }

    public function destroy(Request $request, Domain $domain, PaymentCardType $paymentCardType)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $this->ensureInDomainLocation($domain, $location, $paymentCardType);

        if ($paymentCardType->sales()->exists()) {
            $paymentCardType->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Payment channel deactivated because it is used on past sales.',
            ]);
        }

        $paymentCardType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payment channel deleted.',
        ]);
    }

    /** Channel names must be unique per store, or cashiers can't tell them apart at checkout. */
    private function uniqueNameInLocation(Domain $domain, InventoryLocation $location): Unique
    {
        return Rule::unique('payment_card_types', 'name')
            ->where('domain', $domain->name_slug)
            ->where('location_id', $location->id);
    }

    /** @return array<string, string> */
    private function uniqueNameMessage(): array
    {
        return ['name.unique' => 'A payment channel with this name already exists at this store.'];
    }

    private function ensureInDomainLocation(Domain $domain, InventoryLocation $location, PaymentCardType $paymentCardType): void
    {
        if (
            $paymentCardType->domain !== $domain->name_slug
            || (int) $paymentCardType->location_id !== (int) $location->id
        ) {
            abort(403);
        }
    }
}
