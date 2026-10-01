<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesHistoryResource;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\VoidLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only history of completed sales (anything past the open-cart "pending" state).
 * Cashiers and other staff below manager only ever see their own sales.
 */
class SalesHistoryController extends Controller
{
    /** Statuses a sale can have once it has left the cart. */
    private const STATUSES = ['paid', 'partial', 'refunded'];

    private const PAYMENT_METHODS = ['cash', 'card', 'e-wallet', 'credit'];

    public function index(Request $request, Domain $domain)
    {
        $filters = $this->resolveFilters($request, $domain);
        $query = $this->baseQuery($domain, $filters);

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as gross')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discounts')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as vat')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as net')
            ->first();
        $salesWithVoids = (clone $query)->whereHas('saleItems', fn ($q) => $q->onlyTrashed())->count();

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $sales = $this->withListRelations($query)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('SalesHistory/Index', [
            'items' => SalesHistoryResource::collection($sales),
            'filters' => [
                'start_date' => $filters['start']->toDateString(),
                'end_date' => $filters['end']->toDateString(),
                'location_id' => $filters['location_id'],
                'user_id' => $filters['user_id'],
                'payment_method' => $filters['payment_method'],
                'payment_status' => $filters['payment_status'],
                'search' => $filters['search'],
            ],
            'summary' => [
                'sales_count' => (int) $summary->sales_count,
                'gross' => round((float) $summary->gross, 2),
                'discounts' => round((float) $summary->discounts, 2),
                'vat' => round((float) $summary->vat, 2),
                'net' => round((float) $summary->net, 2),
                'sales_with_voids' => $salesWithVoids,
            ],
            'options' => [
                'locations' => $this->canSeeAllSales($request->user())
                    ? InventoryLocation::query()->forDomain($domain->name_slug)->active()->orderBy('name')->get(['id', 'name'])
                    : [],
                'cashiers' => $this->canSeeAllSales($request->user())
                    ? User::query()->where('domain', $domain->name_slug)->orderBy('name')->get(['id', 'name'])
                    : [],
                'payment_methods' => self::PAYMENT_METHODS,
                'statuses' => self::STATUSES,
            ],
            'restrictedToOwnSales' => ! $this->canSeeAllSales($request->user()),
            'domainName' => $domain->name,
        ]);
    }

    /**
     * Everything the detail drawer and the reprinted receipt need, voided lines included.
     */
    public function show(Request $request, Domain $domain, Sale $sale): JsonResponse
    {
        if ($sale->domain !== $domain->name_slug || ! in_array($sale->payment_status, self::STATUSES, true)) {
            abort(404);
        }
        if (! $this->canSeeAllSales($request->user()) && (int) $sale->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $sale->load([
            'customer:id,name',
            'user:id,name',
            'location:id,name',
            'paymentCardType:id,name',
            'saleDiscounts.discount:id,name',
            'saleDiscounts.mandatoryDiscount:id,name',
        ]);

        $items = SaleItem::withTrashed()
            ->where('sale_id', $sale->id)
            ->with('product:id,name')
            ->orderBy('id')
            ->get();

        $voidLogs = VoidLog::query()
            ->whereIn('sale_item_id', $items->pluck('id'))
            ->with(['user:id,name', 'approver:id,name', 'saleItem' => fn ($q) => $q->withTrashed()->with('product:id,name')])
            ->orderBy('created_at')
            ->get();

        $timezone = config('app.timezone', 'UTC');
        $date = $sale->transaction_date ? Carbon::parse($sale->transaction_date)->timezone($timezone) : null;

        return response()->json([
            'id' => $sale->id,
            'invoice_number' => $sale->invoice_number,
            'transaction_date_display' => $date?->format('Y-m-d h:i A'),
            'cashier_name' => $sale->user?->name,
            'customer_name' => $sale->customer?->name ?? 'Walk-in',
            'location_name' => $sale->location?->name,
            'payment_method' => $sale->payment_method,
            'payment_card_type' => $sale->paymentCardType?->name,
            'payment_status' => $sale->payment_status,
            'is_credit_sale' => (bool) $sale->is_credit_sale,
            'notes' => $sale->notes,
            'total_amount' => round((float) $sale->total_amount, 2),
            'discount_amount' => round((float) $sale->discount_amount, 2),
            'tax_amount' => round((float) $sale->tax_amount, 2),
            'grand_total' => round((float) $sale->grand_total, 2),
            'loyalty_points_redeemed' => (int) ($sale->loyalty_points_redeemed ?? 0),
            'loyalty_discount_amount' => round((float) ($sale->loyalty_discount_amount ?? 0), 2),
            'vat' => $domain->salesVatSettings(),
            'items' => $items->map(fn (SaleItem $item) => [
                'id' => $item->id,
                'product_name' => $item->product?->name ?? 'Deleted product',
                'quantity' => (float) $item->quantity,
                'unit_price' => round((float) $item->unit_price, 2),
                'discount' => round((float) $item->discount, 2),
                'subtotal' => round((float) $item->subtotal, 2),
                'voided' => $item->trashed(),
            ])->values(),
            'discounts' => $sale->saleDiscounts->map(function ($saleDiscount) {
                // SaleDiscount's `discount` accessor shadows the relation, so read the loaded relations directly.
                $source = $saleDiscount->discount_type === 'mandatory'
                    ? $saleDiscount->getRelation('mandatoryDiscount')
                    : $saleDiscount->getRelation('discount');

                return [
                    'name' => $source?->name ?? 'Discount',
                    'type' => $saleDiscount->discount_type,
                    'amount' => round((float) $saleDiscount->discount_amount, 2),
                ];
            })->values(),
            'void_logs' => $voidLogs->map(fn (VoidLog $log) => [
                'id' => $log->id,
                'product_name' => $log->saleItem?->product?->name,
                'amount' => round((float) $log->amount, 2),
                'reason' => $log->reason,
                'voided_by' => $log->user?->name,
                'approved_by' => $log->approver?->name,
                'voided_at' => $log->created_at?->timezone($timezone)->format('Y-m-d h:i A'),
            ])->values(),
        ]);
    }

    /**
     * The filtered list as CSV — same filters as the page, not paginated.
     */
    public function export(Request $request, Domain $domain): StreamedResponse|JsonResponse
    {
        $filters = $this->resolveFilters($request, $domain);
        $query = $this->baseQuery($domain, $filters);

        $maxRows = config('vat_report.max_export_rows', 50000);
        $count = (clone $query)->count();
        if ($count > $maxRows) {
            return response()->json([
                'message' => "Export exceeds maximum of {$maxRows} rows ({$count} matching). Narrow the filters.",
            ], 422);
        }

        $filename = sprintf(
            'sales-history-%s-%s-%s.csv',
            preg_replace('/[^a-zA-Z0-9_-]+/', '-', $domain->name_slug),
            $filters['start']->format('Y-m-d'),
            $filters['end']->format('Y-m-d')
        );

        $query = $this->withListRelations($query)->orderBy('transaction_date')->orderBy('id');

        return response()->streamDownload(function () use ($query, $request) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'invoice_number', 'transaction_date', 'cashier', 'customer', 'location',
                'payment_method', 'card_type', 'payment_status', 'items', 'voided_items',
                'total_amount', 'discount_amount', 'tax_amount', 'grand_total',
            ]);

            foreach ($query->lazy(500) as $sale) {
                $row = (new SalesHistoryResource($sale))->toArray($request);
                fputcsv($out, [
                    $row['invoice_number'] ?? '#'.$row['id'],
                    $row['transaction_date_display'] ?? '',
                    $row['cashier_name'] ?? '',
                    $row['customer_name'],
                    $row['location_name'] ?? '',
                    $row['payment_method'] ?? '',
                    $row['payment_card_type'] ?? '',
                    $row['payment_status'],
                    $row['items_count'],
                    $row['voided_items_count'],
                    $row['total_amount'],
                    $row['discount_amount'],
                    $row['tax_amount'],
                    $row['grand_total'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Validated filters with defaults applied and access rules enforced.
     *
     * @return array{start: Carbon, end: Carbon, location_id: ?int, user_id: ?int, payment_method: ?string, payment_status: ?string, search: ?string}
     */
    private function resolveFilters(Request $request, Domain $domain): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(self::PAYMENT_METHODS)],
            'payment_status' => ['nullable', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $start = Carbon::parse($validated['start_date'] ?? now()->toDateString())->startOfDay();
        $end = Carbon::parse($validated['end_date'] ?? $start->toDateString())->endOfDay();

        $locationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;
        if ($locationId && ! InventoryLocation::query()->forDomain($domain->name_slug)->whereKey($locationId)->exists()) {
            $locationId = null;
        }

        $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;
        if (! $this->canSeeAllSales($request->user())) {
            $userId = (int) $request->user()->id;
        }

        return [
            'start' => $start,
            'end' => $end,
            'location_id' => $locationId,
            'user_id' => $userId,
            'payment_method' => $validated['payment_method'] ?? null,
            'payment_status' => $validated['payment_status'] ?? null,
            'search' => isset($validated['search']) ? trim($validated['search']) : null,
        ];
    }

    private function baseQuery(Domain $domain, array $filters): Builder
    {
        return Sale::query()
            ->where('domain', $domain->name_slug)
            ->whereBetween('transaction_date', [$filters['start'], $filters['end']])
            ->whereIn('payment_status', $filters['payment_status'] ? [$filters['payment_status']] : self::STATUSES)
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['user_id'], fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['payment_method'], fn ($q, $method) => $q->where('payment_method', $method))
            ->when($filters['search'], function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            });
    }

    private function withListRelations(Builder $query): Builder
    {
        return $query
            ->with(['user:id,name', 'customer:id,name', 'location:id,name', 'paymentCardType:id,name'])
            ->withCount([
                'saleItems as items_count',
                'saleItems as voided_items_count' => fn ($q) => $q->onlyTrashed(),
            ]);
    }

    /**
     * Managers and above see everyone's sales; supervisors and cashiers see only their own.
     * Goes by the assigned role's level: the users.role_level column defaults to 3 (manager)
     * for staff created in the app, so it can't tell a cashier apart.
     */
    private function canSeeAllSales(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->is_super_user) {
            return true;
        }

        $roleLevel = $user->roles->min('level');

        return $roleLevel !== null ? (int) $roleLevel <= 3 : $user->isManagerOrAbove();
    }
}
