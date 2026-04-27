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
            'locations' => $locations,
        ]);
    }
}
