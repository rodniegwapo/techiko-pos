<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\PaymentCardType;
use App\Models\Sale;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentCardTypeController extends Controller
{
    public function index(Request $request, Domain $domain)
    {
        $types = PaymentCardType::query()
            ->forDomain($domain->name_slug)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Wallet/Index', [
            'cardTypes' => $types,
        ]);
    }

    /**
     * JSON list for Sales modal (active types only).
     */
    public function list(Request $request, Domain $domain)
    {
        $types = PaymentCardType::query()
            ->forDomain($domain->name_slug)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return response()->json(['data' => $types]);
    }

    public function store(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $type = PaymentCardType::query()->create([
            'domain' => $domain->name_slug,
            'name' => $validated['name'],
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
        $this->ensureInDomain($domain, $paymentCardType);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

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
        $this->ensureInDomain($domain, $paymentCardType);

        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $base = Sale::query()
            ->where('domain', $domain->name_slug)
            ->where('payment_card_type_id', $paymentCardType->id)
            ->where('payment_status', 'paid')
            ->where('payment_method', 'card');

        $todayTotal = (clone $base)
            ->whereDate('transaction_date', now()->toDateString())
            ->sum('grand_total');

        $yesterdayTotal = (clone $base)
            ->whereDate('transaction_date', now()->subDay()->toDateString())
            ->sum('grand_total');

        $history = (clone $base)
            ->orderByDesc('transaction_date')
            ->paginate($perPage, ['*'], 'page', $page);

        $history->setCollection(
            $history->getCollection()->map(function (Sale $sale) {
                $ts = $sale->transaction_date;
                if ($ts && ! $ts instanceof \Carbon\CarbonInterface) {
                    $ts = \Carbon\Carbon::parse($ts);
                }

                return [
                    'id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'grand_total' => (float) $sale->grand_total,
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

    public function destroy(Domain $domain, PaymentCardType $paymentCardType)
    {
        $this->ensureInDomain($domain, $paymentCardType);

        if ($paymentCardType->sales()->exists()) {
            $paymentCardType->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Card type deactivated because it is used on past sales.',
            ]);
        }

        $paymentCardType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Card type deleted.',
        ]);
    }

    private function ensureInDomain(Domain $domain, PaymentCardType $paymentCardType): void
    {
        if ($paymentCardType->domain !== $domain->name_slug) {
            abort(403);
        }
    }
}
