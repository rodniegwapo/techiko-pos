<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\Product\Product;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\ProductInventory;
use App\Services\InventoryService;
use App\Services\InventoryAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    protected $inventoryService;
    protected $inventoryAnalyticsService;

    public function __construct(InventoryService $inventoryService, InventoryAnalyticsService $inventoryAnalyticsService)
    {
        $this->inventoryService = $inventoryService;
        $this->inventoryAnalyticsService = $inventoryAnalyticsService;
    }

    public function index(Request $request)
    {
        $location = $request->location_id 
            ? InventoryLocation::findOrFail($request->location_id)
            : InventoryLocation::getDefault();

        $stats = [
            'kpis' => $this->getKPIs($location),
            'sales_analytics' => $this->getSalesAnalytics(),
            'inventory_alerts' => $this->getInventoryAlerts($location),
            'customer_insights' => $this->getCustomerInsights(),
            'recent_transactions' => $this->getRecentTransactions(),
            'top_products' => $this->getTopProducts($location),
            'operational_status' => $this->getOperationalStatus(),
        ];

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'locations' => InventoryLocation::active()->get(),
            'currentLocation' => $location,
            'filters' => $request->only(['location_id']),
        ]);
    }

    private function getKPIs($location)
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $lastWeek = now()->subWeek()->startOfWeek();

        // Today's Sales
        $todaySales = Sale::where('payment_status', 'paid')
            ->whereDate('created_at', $today)
            ->sum('grand_total') ?? 0;

        $yesterdaySales = Sale::where('payment_status', 'paid')
            ->whereDate('created_at', $yesterday)
            ->sum('grand_total') ?? 0;

        $salesGrowth = $yesterdaySales > 0 
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : 0;

        // Total Revenue (This Week)
        $thisWeekRevenue = Sale::where('payment_status', 'paid')
            ->where('created_at', '>=', $thisWeek)
            ->sum('grand_total') ?? 0;

        $lastWeekRevenue = Sale::where('payment_status', 'paid')
            ->where('created_at', '>=', $lastWeek)
            ->where('created_at', '<', $thisWeek)
            ->sum('grand_total') ?? 0;

        $revenueGrowth = $lastWeekRevenue > 0 
            ? round((($thisWeekRevenue - $lastWeekRevenue) / $lastWeekRevenue) * 100, 1)
            : 0;

        // Active Orders (Pending Sales)
        $activeOrders = Sale::where('payment_status', 'pending')->count();

        // Inventory Value
        $inventoryValue = $location ? $location->getTotalInventoryValue() : 0;
        $inventoryReport = $this->inventoryService->getInventoryReport($location);
        $inventoryGrowth = -2; // This could be calculated based on previous period

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
                'growth' => $inventoryGrowth,
                'label' => 'Inventory Value',
                'icon' => 'package',
                'color' => 'purple'
            ]
        ];
    }

    private function getSalesAnalytics()
    {
        // Last 7 days sales data
        $salesData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $sales = Sale::where('payment_status', 'paid')
                ->whereDate('created_at', $date)
                ->sum('grand_total') ?? 0;
            
            $salesData[] = [
                'date' => $date->format('M d'),
                'sales' => $sales,
                'transactions' => Sale::where('payment_status', 'paid')
                    ->whereDate('created_at', $date)
                    ->count()
            ];
        }

        // Payment methods distribution
        $paymentMethods = Sale::where('payment_status', 'paid')
            ->where('created_at', '>=', now()->subDays(7))
            ->select('payment_method', DB::raw('SUM(grand_total) as total'))
            ->groupBy('payment_method')
            ->get()
            ->map(function ($item) {
                return [
                    'method' => ucfirst($item->payment_method ?? 'cash'),
                    'total' => $item->total,
                    'percentage' => 0 // Will be calculated in frontend
                ];
            });

        return [
            'daily_sales' => $salesData,
            'payment_methods' => $paymentMethods
        ];
    }

    private function getInventoryAlerts($location)
    {
        $inventoryReport = $this->inventoryService->getInventoryReport($location);
        
        // Low stock products
        $lowStockProducts = $inventoryReport['low_stock_products'] ?? [];
        
        // Out of stock products
        $outOfStockProducts = Product::outOfStock($location)->limit(5)->get();
        
        // Recent stock movements
        $recentMovements = InventoryMovement::with(['product', 'user'])
            ->where('location_id', $location->id ?? null)
            ->latest()
            ->limit(5)
            ->get();

        return [
            'low_stock_count' => count($lowStockProducts),
            'out_of_stock_count' => $outOfStockProducts->count(),
            'low_stock_products' => $lowStockProducts->take(5),
            'out_of_stock_products' => $outOfStockProducts,
            'recent_movements' => $recentMovements
        ];
    }

    private function getCustomerInsights()
    {
        $totalCustomers = Customer::count();
        $loyaltyMembers = Customer::whereNotNull('loyalty_points')->count();
        $newCustomersToday = Customer::whereDate('created_at', now()->startOfDay())->count();
        
        // Customer tier distribution
        $tierDistribution = DB::table('customers')
            ->select(
                DB::raw('COALESCE(tier, "bronze") as tier'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('tier')
            ->get()
            ->map(function ($item) {
                return [
                    'tier' => ucfirst($item->tier),
                    'count' => $item->count,
                    'color' => $this->getTierColor($item->tier)
                ];
            });

        return [
            'total_customers' => $totalCustomers,
            'loyalty_members' => $loyaltyMembers,
            'new_customers_today' => $newCustomersToday,
            'tier_distribution' => $tierDistribution
        ];
    }

    private function getRecentTransactions()
    {
        return Sale::with(['customer', 'saleItems.product'])
            ->where('payment_status', 'paid')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'customer_name' => $sale->customer?->name ?? 'Walk-in Customer',
                    'total' => $sale->grand_total,
                    'items_count' => $sale->saleItems->count(),
                    'payment_method' => $sale->payment_method,
                    'created_at' => $sale->created_at,
                    'status' => $sale->payment_status
                ];
            });
    }

    private function getTopProducts($location)
    {
        return InventoryMovement::where('movement_type', 'sale')
            ->where('location_id', $location->id ?? null)
            ->where('created_at', '>=', now()->subDays(7))
            ->select('product_id', DB::raw('SUM(ABS(quantity_change)) as total_sold'))
            ->groupBy('product_id')
            ->orderBy('total_sold', 'desc')
            ->limit(5)
            ->with('product')
            ->get()
            ->map(function ($movement) {
                return [
                    'product' => $movement->product,
                    'quantity_sold' => $movement->total_sold
                ];
            });
    }

    private function getOperationalStatus()
    {
        // Try to get active users from sessions table, fallback to 1 if table doesn't exist
        try {
            $activeUsers = DB::table('sessions')
                ->where('last_activity', '>', now()->subMinutes(30)->timestamp)
                ->count();
        } catch (\Exception $e) {
            $activeUsers = 1; // Fallback value
        }

        $voidsToday = Sale::where('payment_status', 'voided')
            ->whereDate('created_at', now()->startOfDay())
            ->count();

        return [
            'active_users' => $activeUsers,
            'voids_today' => $voidsToday,
            'system_status' => 'online'
        ];
    }

    private function getTierColor($tier)
    {
        return match($tier) {
            'bronze' => '#CD7F32',
            'silver' => '#C0C0C0',
            'gold' => '#FFD700',
            'platinum' => '#E5E4E2',
            default => '#CD7F32'
        };
    }
}
