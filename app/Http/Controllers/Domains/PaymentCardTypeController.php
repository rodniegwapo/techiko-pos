<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\WalletCashBridgeExpected;
use App\Support\Wallet\WalletCashDailyExpected;
use App\Support\Wallet\WalletLedgerViewData;
use App\Support\Wallet\WalletLocationResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentCardTypeController extends Controller
{
    public function index(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $businessDate = $this->resolveBusinessDate($request);

        $types = PaymentCardType::query()
            ->forDomainLocation($domain->name_slug, $location->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $walletCashTotals = $this->paidSalesTotalsByPaymentMethod($domain, $location, 'cash');
        $walletCreditTotals = $this->paidSalesTotalsByPaymentMethod($domain, $location, 'credit');

        $props = [
            'cardTypes' => $types,
            'walletCashTotals' => $walletCashTotals,
            'walletCreditTotals' => $walletCreditTotals,
            'ledger' => null,
            'runningCashBalance' => null,
            'activeLocation' => [
                'id' => $location->id,
                'name' => $location->name,
            ],
            'cashControl' => $this->cashControlSnapshot(
                $domain,
                $location,
                $businessDate,
                (int) $request->user()->id
            ),
        ];

        if ($request->user()->hasPermissionToRoute('wallet-cash-ledger.index')) {
            $ledger = WalletLedgerViewData::buildPaginated($request, $domain, $location);
            $today = now()->toDateString();
            $ledger['todayManualNet'] = WalletLedgerViewData::todayManualNet($domain, $location, $today);
            $props['ledger'] = $ledger;
            $props['runningCashBalance'] = WalletLedgerViewData::runningCashBalance($domain, $location);
        }

        return Inertia::render('Wallet/Index', $props);
    }

    private function resolveBusinessDate(Request $request): string
    {
        $validated = $request->validate([
            'business_date' => ['sometimes', 'date', 'before_or_equal:today'],
        ]);

        return (string) ($validated['business_date'] ?? now()->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    private function cashControlSnapshot(Domain $domain, InventoryLocation $location, string $businessDate, int $viewerUserId): array
    {
        $recon = WalletCashReconciliation::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('business_date', $businessDate)
            ->with('countedBy:id,name')
            ->first();

        $openingIsSaved = $recon !== null;
        $openingSuggestion = null;
        $suggestionSourceDate = null;

        if ($openingIsSaved) {
            $openingCash = (float) $recon->opening_cash;
        } else {
            $prev = WalletCashReconciliation::query()
                ->forWalletContext($domain->name_slug, $location->id)
                ->whereDate('business_date', '<', $businessDate)
                ->whereNotNull('counted_cash')
                ->orderByDesc('business_date')
                ->first();

            $openingSuggestion = $prev ? (float) $prev->counted_cash : null;
            $suggestionSourceDate = $prev?->business_date?->toDateString();
            $openingCash = (float) ($openingSuggestion ?? 0);
        }

        $dailyExpected = WalletCashDailyExpected::compute(
            $domain->name_slug,
            (int) $location->id,
            $businessDate,
            $recon
        );
        $paidCashSales = $dailyExpected['paid_cash_sales'];
        $manualIn = $dailyExpected['manual_in'];
        $manualOut = $dailyExpected['manual_out'];
        $expectedCash = $dailyExpected['expected_cash'];

        $countedCash = $recon?->counted_at !== null ? (float) $recon->counted_cash : null;
        $variance = $countedCash === null ? null : round($countedCash - $expectedCash, 2);

        $bridge = WalletCashBridgeExpected::compute(
            $domain->name_slug,
            (int) $location->id,
            $businessDate,
            $countedCash
        );

        $countedByUser = null;
        if ($recon?->counted_at !== null && $recon->counted_by) {
            $cb = $recon->relationLoaded('countedBy') ? $recon->countedBy : User::query()->find((int) $recon->counted_by);
            if ($cb) {
                $countedByUser = [
                    'id' => (int) $cb->id,
                    'name' => (string) $cb->name,
                ];
            }
        }

        $openingLastUpdatedByUser = null;
        $openingLastUpdatedAt = null;

        if ($recon !== null) {
            $lastOpeningAudit = WalletCashOpeningAudit::query()
                ->where('reconciliation_id', $recon->id)
                ->with('changedBy:id,name')
                ->orderByDesc('changed_at')
                ->first();

            if ($lastOpeningAudit?->changedBy !== null) {
                $openingLastUpdatedByUser = [
                    'id' => (int) $lastOpeningAudit->changedBy->id,
                    'name' => (string) $lastOpeningAudit->changedBy->name,
                ];
                $openingLastUpdatedAt = $lastOpeningAudit->changed_at?->toIso8601String();
            }

            if ($openingLastUpdatedByUser === null) {
                $openingMovement = WalletCashMovement::query()
                    ->forWalletContext($domain->name_slug, (int) $location->id)
                    ->whereDate('movement_date', $businessDate)
                    ->where('notes', WalletCashBridgeExpected::NOTE_OPENING)
                    ->with('user:id,name')
                    ->first();

                if ($openingMovement?->user !== null) {
                    $openingLastUpdatedByUser = [
                        'id' => (int) $openingMovement->user->id,
                        'name' => (string) $openingMovement->user->name,
                    ];
                    $openingLastUpdatedAt = $openingMovement->updated_at?->toIso8601String()
                        ?? $openingMovement->created_at?->toIso8601String();
                }
            }
        }

        $openingAuditHistory = [];
        if ($recon !== null) {
            $openingAuditHistory = WalletCashOpeningAudit::query()
                ->where('reconciliation_id', $recon->id)
                ->with('changedBy:id,name')
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->limit(25)
                ->get()
                ->map(static function (WalletCashOpeningAudit $a): array {
                    return [
                        'id' => $a->id,
                        'old_opening_cash' => $a->old_opening_cash !== null ? (float) $a->old_opening_cash : null,
                        'new_opening_cash' => (float) $a->new_opening_cash,
                        'delta_amount' => (float) $a->delta_amount,
                        'reason' => $a->reason,
                        'changed_at' => $a->changed_at?->toIso8601String(),
                        'changed_by_user' => $a->changedBy !== null
                            ? [
                                'id' => (int) $a->changedBy->id,
                                'name' => (string) $a->changedBy->name,
                            ]
                            : null,
                    ];
                })
                ->values()
                ->all();
        }

        $countSubmissionHistory = WalletCashCountSubmission::query()
            ->forWalletContext($domain->name_slug, (int) $location->id)
            ->whereDate('business_date', $businessDate)
            ->with('countedBy:id,name')
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static function (WalletCashCountSubmission $row): array {
                return [
                    'id' => $row->id,
                    'counted_cash' => (float) $row->counted_cash,
                    'expected_cash_snapshot' => $row->expected_cash_snapshot !== null ? (float) $row->expected_cash_snapshot : null,
                    'variance_snapshot' => $row->variance_snapshot !== null ? (float) $row->variance_snapshot : null,
                    'notes' => $row->notes,
                    'counted_at' => $row->counted_at?->toIso8601String(),
                    'counted_by_user' => $row->countedBy !== null
                        ? [
                            'id' => (int) $row->countedBy->id,
                            'name' => (string) $row->countedBy->name,
                        ]
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'business_date' => $businessDate,
            'opening_cash' => $openingCash,
            'paid_cash_sales' => $paidCashSales,
            'manual_in' => $manualIn,
            'manual_out' => $manualOut,
            'expected_cash' => $expectedCash,
            'counted_cash' => $countedCash,
            'variance' => $variance,
            'status' => $countedCash === null ? 'pending' : 'counted',
            'notes' => $recon?->notes,
            'counted_at' => $recon?->counted_at?->toIso8601String(),
            'counted_by' => ($recon?->counted_at !== null && $recon->counted_by)
                ? (int) $recon->counted_by
                : null,
            'counted_by_user' => $countedByUser,
            'opening_last_updated_by_user' => $openingLastUpdatedByUser,
            'opening_last_updated_at' => $openingLastUpdatedAt,
            'opening_is_saved' => $openingIsSaved,
            'opening_suggestion' => $openingSuggestion,
            'suggestion_source_date' => $suggestionSourceDate,
            'is_closed' => (bool) ($recon?->is_closed ?? false),
            'closed_at' => $recon?->closed_at?->toIso8601String(),
            'closed_by' => $recon?->closed_by ? (int) $recon->closed_by : null,
            'can_reopen' => $recon?->is_closed
                ? (int) ($recon->closed_by ?? 0) === $viewerUserId
                : false,
            'bridge_anchor_business_date' => $bridge['bridge_anchor_business_date'],
            'bridge_anchor_counted_cash' => $bridge['bridge_anchor_counted_cash'],
            'bridge_expected_cash' => $bridge['bridge_expected_cash'],
            'bridge_variance' => $bridge['bridge_variance'],
            'bridge_day_span' => $bridge['bridge_day_span'],
            'bridge_span_warning' => $bridge['bridge_span_warning'],
            'count_submission_history' => $countSubmissionHistory,
            'opening_audit_history' => $openingAuditHistory,
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
            ->where('payment_status', 'paid')
            ->where('payment_method', $paymentMethod);

        $todayTotal = (clone $base)
            ->whereDate('transaction_date', now()->toDateString())
            ->sum('grand_total');

        $yesterdayTotal = (clone $base)
            ->whereDate('transaction_date', now()->subDay()->toDateString())
            ->sum('grand_total');

        return [
            'today_total' => (float) $todayTotal,
            'yesterday_total' => (float) $yesterdayTotal,
        ];
    }

    /**
     * JSON list for Sales modal (active types only).
     */
    public function list(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        $types = PaymentCardType::query()
            ->forDomainLocation($domain->name_slug, $location->id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return response()->json(['data' => $types]);
    }

    public function store(Request $request, Domain $domain)
    {
        $location = WalletLocationResolver::resolve($request, $domain);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $type = PaymentCardType::query()->create([
            'domain' => $domain->name_slug,
            'location_id' => $location->id,
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
        $location = WalletLocationResolver::resolve($request, $domain);
        $this->ensureInDomainLocation($domain, $location, $paymentCardType);

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
        $location = WalletLocationResolver::resolve($request, $domain);
        $this->ensureInDomainLocation($domain, $location, $paymentCardType);

        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $base = Sale::query()
            ->where('domain', $domain->name_slug)
            ->where('location_id', $location->id)
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
                if ($ts && ! $ts instanceof CarbonInterface) {
                    $ts = Carbon::parse($ts);
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

    public function destroy(Request $request, Domain $domain, PaymentCardType $paymentCardType)
    {
        $location = WalletLocationResolver::resolve($request, $domain);
        $this->ensureInDomainLocation($domain, $location, $paymentCardType);

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
