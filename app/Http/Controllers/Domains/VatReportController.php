<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VatReportController extends Controller
{
    public function index(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->endOfMonth()->toDateString();

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $query = Sale::query()
            ->where('domain', $domain->name_slug)
            ->where('payment_status', 'paid')
            ->whereBetween('transaction_date', [$start, $end]);

        $locationId = $validated['location_id'] ?? null;
        if ($locationId) {
            $inDomain = InventoryLocation::query()
                ->forDomain($domain->name_slug)
                ->whereKey($locationId)
                ->exists();
            if ($inDomain) {
                $query->where('location_id', $locationId);
            } else {
                $locationId = null;
            }
        }

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
                'location_id' => $locationId,
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
}
