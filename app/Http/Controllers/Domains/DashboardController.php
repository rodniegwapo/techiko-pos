<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Helpers;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the dashboard for a specific domain
     */
    public function index(Request $request, Domain $domain)
    {
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));


        // Only essential KPIs - let frontend handle complex analytics
        $stats = [
            'kpis' => $this->getKPIs($location, $domain->name_slug),
            'recent_transactions' => $this->getRecentTransactions($domain->name_slug, $location),
        ];

        $availableLocations = InventoryLocation::active()->forDomain($domain->name_slug)->get();

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'locations' => $availableLocations,
        ]);
    }

    /**
     * Get sales chart data - return raw data for frontend processing
     */
    public function getSalesChartData(Request $request): JsonResponse
    {
        $request->validate([
            'time_range' => 'required|in:daily,weekly,monthly',
            'location_id' => 'nullable'
        ]);

        $domainParam = $request->route('domain');
        $domain = $domainParam instanceof Domain ? $domainParam->name_slug : $domainParam;
        $timeRange = $request->input('time_range', 'daily');

        // Get the active location for filtering
        $location = Helpers::getActiveLocation($domainParam, $request->input('location_id'));

        // Return raw data - let frontend handle formatting
        $salesQuery = Sale::where('domain', $domain)
            ->where('payment_status', 'paid')
            ->where('created_at', '>=', $this->getStartDate($timeRange));

        // Add location filtering if location exists
        if ($location) {
            $salesQuery->where('location_id', $location->id);
        }

        $sales = $salesQuery->select(['created_at', 'grand_total', 'payment_method'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'raw_data' => $sales,
            'time_range' => $timeRange
        ]);
    }

    /**
     * Get KPIs for the dashboard
     */
    protected function getKPIs($location, $domain = null)
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $lastWeek = now()->subWeek()->startOfWeek();

        // Build base query with domain AND location filtering
        $salesQuery = Sale::where('payment_status', 'paid');
        if ($domain) {
            $salesQuery->where('domain', $domain);
        }
        if ($location) {
            $salesQuery->where('location_id', $location->id);
        }

        // Today's Sales
        $todaySales = (clone $salesQuery)
            ->whereDate('created_at', $today)
            ->sum('grand_total') ?? 0;

        $yesterdaySales = (clone $salesQuery)
            ->whereDate('created_at', $yesterday)
            ->sum('grand_total') ?? 0;

        $salesGrowth = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : 0;

        // Total Revenue (This Week)
        $thisWeekRevenue = (clone $salesQuery)
            ->where('created_at', '>=', $thisWeek)
            ->sum('grand_total') ?? 0;

        $lastWeekRevenue = (clone $salesQuery)
            ->where('created_at', '>=', $lastWeek)
            ->where('created_at', '<', $thisWeek)
            ->sum('grand_total') ?? 0;

        $revenueGrowth = $lastWeekRevenue > 0
            ? round((($thisWeekRevenue - $lastWeekRevenue) / $lastWeekRevenue) * 100, 1)
            : 0;

        // Active Orders (Pending Sales)
        $pendingQuery = Sale::where('payment_status', 'pending');
        if ($domain) {
            $pendingQuery->where('domain', $domain);
        }
        if ($location) {
            $pendingQuery->where('location_id', $location->id);
        }

        $activeOrders = (clone $pendingQuery)->count();

        // Inventory Value
        $inventoryValue = $location ? $location->getTotalInventoryValue() : 0;

        return [
            'today_sales' => [
                'value' => $todaySales,
                'growth' => $salesGrowth,
                'label' => 'Today\'s Sales',
                'icon' => 'dollar',
                'color' => 'green'
            ],
            'total_revenue' => [
                'value' => $thisWeekRevenue,
                'growth' => $revenueGrowth,
                'label' => 'This Week Revenue',
                'icon' => 'trending-up',
                'color' => 'blue'
            ],
            'active_orders' => [
                'value' => $activeOrders,
                'growth' => 0,
                'label' => 'Pending Orders',
                'icon' => 'shopping-cart',
                'color' => 'orange'
            ],
            'inventory_value' => [
                'value' => $inventoryValue,
                'growth' => 0,
                'label' => 'Inventory Value',
                'icon' => 'package',
                'color' => 'purple'
            ]
        ];
    }

    /**
     * Get recent transactions
     */
    protected function getRecentTransactions($domain = null, $location = null)
    {
        $transactionsQuery = Sale::with(['customer', 'saleItems.product'])
            ->where('payment_status', 'paid');

        if ($domain) {
            $transactionsQuery->where('domain', $domain);
        }
        if ($location) {
            $transactionsQuery->where('location_id', $location->id);
        }

        return $transactionsQuery->latest()
            ->limit(10)
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'customer_name' => $sale->customer->name ?? 'Walk-in Customer',
                    'total' => $sale->grand_total,
                    'items_count' => $sale->saleItems->count(),
                    'date' => $sale->created_at->format('M d, Y H:i'),
                    'status' => $sale->payment_status
                ];
            });
    }

    /**
     * Get start date based on time range
     */
    private function getStartDate($timeRange)
    {
        return match ($timeRange) {
            'daily' => now()->subDays(7),
            'weekly' => now()->subWeeks(4),
            'monthly' => now()->subMonths(12),
            default => now()->subDays(7)
        };
    }
}
