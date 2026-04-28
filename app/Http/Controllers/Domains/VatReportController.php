<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VatReportController extends Controller
{
    /**
     * Full VAT register as JSON (same filters/cap as CSV) for client-side Excel (exceljs).
     */
    public function exportJson(Request $request, Domain $domain): JsonResponse
    {
        $validated = $this->validateVatReportExportRequest($request);

        [$start, $end] = $this->resolveDateRange($validated);
        $effectiveLocationId = $this->resolveEffectiveLocationId($domain, $validated['location_id'] ?? null);
        $query = $this->baseVatSalesQuery($domain, $start, $end, $effectiveLocationId);

        $maxRows = config('vat_report.max_export_rows', 50000);
        $count = (clone $query)->count();
        if ($count > $maxRows) {
            return response()->json([
                'message' => "Export exceeds maximum of {$maxRows} rows ({$count} matching). Narrow the date range or location filter.",
            ], 422);
        }

        $timezone = config('app.timezone', 'UTC');

        $totalVat = (clone $query)->sum('tax_amount');
        $grossSales = (clone $query)->sum('grand_total');

        $transactions = [];
        foreach ($this->iterateTransactionRowsForExport($query, $timezone) as $row) {
            $transactions[] = $row;
        }

        return response()->json([
            'transactions' => $transactions,
            'summary' => [
                'total_vat' => (float) $totalVat,
                'gross_sales' => (float) $grossSales,
                'sales_count' => $count,
            ],
            'filters' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'location_id' => $effectiveLocationId,
            ],
            'domain' => [
                'name' => $domain->name,
                'name_slug' => $domain->name_slug,
            ],
        ]);
    }

    public function index(Request $request, Domain $domain)
    {
        $validated = $this->validateVatReportRequest($request);

        [$start, $end] = $this->resolveDateRange($validated);
        $effectiveLocationId = $this->resolveEffectiveLocationId($domain, $validated['location_id'] ?? null);
        $query = $this->baseVatSalesQuery($domain, $start, $end, $effectiveLocationId);

        $totalVat = (clone $query)->sum('tax_amount');
        $grossSales = (clone $query)->sum('grand_total');
        $salesCount = (clone $query)->count();

        $timezone = config('app.timezone', 'UTC');
        $perPage = 100;
        $page = max(1, (int) ($validated['page'] ?? 1));

        $transactionsPaginator = (clone $query)
            ->select([
                'id',
                'transaction_date',
                'invoice_number',
                'grand_total',
                'tax_amount',
                'payment_method',
                'customer_id',
                'location_id',
            ])
            ->with([
                'customer:id,name',
                'location:id,name',
            ])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $transactionRows = $transactionsPaginator->getCollection()->map(function (Sale $sale) use ($timezone) {
            return $this->transactionRowPayload($sale, $timezone);
        })->values();

        $transactionsPayload = [
            'data' => $transactionRows,
            'meta' => [
                'current_page' => $transactionsPaginator->currentPage(),
                'last_page' => $transactionsPaginator->lastPage(),
                'per_page' => $transactionsPaginator->perPage(),
                'total' => $transactionsPaginator->total(),
                'from' => $transactionsPaginator->firstItem(),
                'to' => $transactionsPaginator->lastItem(),
            ],
        ];

        $locations = InventoryLocation::query()
            ->forDomain($domain->name_slug)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Reports/VatReport', [
            'filters' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'location_id' => $effectiveLocationId,
            ],
            'summary' => [
                'total_vat' => (float) $totalVat,
                'gross_sales' => (float) $grossSales,
                'sales_count' => (int) $salesCount,
            ],
            'transactions' => $transactionsPayload,
            'locations' => $locations,
        ]);
    }

    /**
     * Full VAT register as CSV (same filters as index; not paginated).
     */
    public function export(Request $request, Domain $domain): StreamedResponse|JsonResponse
    {
        $validated = $this->validateVatReportExportRequest($request);

        [$start, $end] = $this->resolveDateRange($validated);
        $effectiveLocationId = $this->resolveEffectiveLocationId($domain, $validated['location_id'] ?? null);
        $query = $this->baseVatSalesQuery($domain, $start, $end, $effectiveLocationId);

        $maxRows = config('vat_report.max_export_rows', 50000);
        $count = (clone $query)->count();
        if ($count > $maxRows) {
            return response()->json([
                'message' => "Export exceeds maximum of {$maxRows} rows ({$count} matching). Narrow the date range or location filter.",
            ], 422);
        }

        $timezone = config('app.timezone', 'UTC');
        $filename = sprintf(
            'vat-register-%s-%s-%s.csv',
            preg_replace('/[^a-zA-Z0-9_-]+/', '-', $domain->name_slug),
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );

        $headers = [
            'id',
            'transaction_date_iso',
            'transaction_date_display',
            'invoice_number',
            'reference',
            'customer_name',
            'location_name',
            'payment_method',
            'taxable_net',
            'tax_amount',
            'grand_total',
        ];

        return response()->streamDownload(function () use ($query, $timezone, $headers) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $headers);

            foreach ($this->iterateTransactionRowsForExport($query, $timezone) as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['transaction_date_iso'] ?? '',
                    $row['transaction_date_display'],
                    $row['invoice_number'] ?? '',
                    $row['reference'],
                    $row['customer_name'],
                    $row['location_name'],
                    $row['payment_method'],
                    $row['taxable_net'],
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
     * Rows for CSV/JSON export: same selects/order as lazy iterator (shared).
     *
     * @return \Traversable<int, array<string, mixed>>
     */
    private function iterateTransactionRowsForExport(Builder $baseQuery, string $timezone): iterable
    {
        foreach (
            $this->transactionsQueryForExport($baseQuery)->lazy(500) as $sale
        ) {
            yield $this->transactionRowPayload($sale, $timezone);
        }
    }

    /**
     * Clone of base VAT query scoped for exporting (relations + ordering).
     */
    private function transactionsQueryForExport(Builder $baseQuery): Builder
    {
        return $baseQuery->clone()
            ->select([
                'id',
                'transaction_date',
                'invoice_number',
                'grand_total',
                'tax_amount',
                'payment_method',
                'customer_id',
                'location_id',
            ])
            ->with([
                'customer:id,name',
                'location:id,name',
            ])
            ->orderBy('transaction_date')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVatReportRequest(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVatReportExportRequest(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(array $validated): array
    {
        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        return [$start, $end];
    }

    private function resolveEffectiveLocationId(Domain $domain, ?int $locationId): ?int
    {
        if (! $locationId) {
            return null;
        }

        $inDomain = InventoryLocation::query()
            ->forDomain($domain->name_slug)
            ->whereKey($locationId)
            ->exists();

        return $inDomain ? $locationId : null;
    }

    /**
     * Paid sales in date range for domain; optional location when valid.
     */
    private function baseVatSalesQuery(Domain $domain, Carbon $start, Carbon $end, ?int $effectiveLocationId): Builder
    {
        $query = Sale::query()
            ->where('domain', $domain->name_slug)
            ->where('payment_status', 'paid')
            ->whereBetween('transaction_date', [$start, $end]);

        if ($effectiveLocationId) {
            $query->where('location_id', $effectiveLocationId);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionRowPayload(Sale $sale, string $timezone): array
    {
        $gt = round((float) $sale->grand_total, 2);
        $tax = round((float) $sale->tax_amount, 2);

        $td = $sale->transaction_date !== null
            ? Carbon::parse($sale->transaction_date)->timezone($timezone)
            : null;
        $transactionDateDisplay = $td ? $td->format('Y-m-d H:i') : '';

        return [
            'id' => $sale->id,
            'transaction_date_iso' => $td?->toIso8601String(),
            'transaction_date_display' => $transactionDateDisplay,
            'invoice_number' => $sale->invoice_number,
            'reference' => $sale->invoice_number ? (string) $sale->invoice_number : '#'.$sale->id,
            'customer_name' => $sale->customer?->name ?? 'Walk-in',
            'location_name' => $sale->location?->name ?? '—',
            'payment_method' => $sale->payment_method ?? '',
            'tax_amount' => $tax,
            'grand_total' => $gt,
            'taxable_net' => max(0, round($gt - $tax, 2)),
        ];
    }
}
