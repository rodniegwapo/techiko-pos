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

    public function index(Request $request, $domain = null)
    {
        $location = $request->location_id
            ? InventoryLocation::findOrFail($request->location_id)
            : InventoryLocation::getDefault();

        $stats = [
            'kpis' => $this->getKPIs($location, $domain),
            'sales_analytics' => $this->getSalesAnalytics($domain),
            'inventory_alerts' => $this->getInventoryAlerts($location, $domain),
            'customer_insights' => $this->getCustomerInsights($domain),
            'recent_transactions' => $this->getRecentTransactions($domain),
            'top_products' => $this->getTopProducts($location, $domain),
            'operational_status' => $this->getOperationalStatus($domain),
        ];

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'locations' => InventoryLocation::active()->get(),
            'currentLocation' => $location,
            'filters' => $request->only(['location_id']),
        ]);
    }

    private function getKPIs($location, $domain = null)
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $lastWeek = now()->subWeek()->startOfWeek();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        // Build base query with domain filtering
        $salesQuery = Sale::where('payment_status', 'paid');
        if ($domain) {
            $salesQuery->where('domain', $domain);
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

        // Active Orders (Pending Sales) - Compare with yesterday
        $pendingQuery = Sale::where('payment_status', 'pending');
        if ($domain) {
            $pendingQuery->where('domain', $domain);
        }

        $activeOrders = (clone $pendingQuery)->count();
        $yesterdayActiveOrders = (clone $pendingQuery)
            ->whereDate('created_at', $yesterday)
            ->count();

        $ordersGrowth = $yesterdayActiveOrders > 0
            ? round((($activeOrders - $yesterdayActiveOrders) / $yesterdayActiveOrders) * 100, 1)
            : ($activeOrders > 0 ? 100 : 0);

        // Inventory Value - Compare with last month
        if ($domain) {
            // Domain-specific view: use the provided location
            $inventoryValue = $location ? $location->getTotalInventoryValue() : 0;
        } else {
            // Global view: sum inventory from ALL locations across ALL domains
            $inventoryValue = \App\Models\ProductInventory::sum('total_value');
        }
        
        // Calculate inventory value for last month (simplified - you might want to store historical data)
        $lastMonthInventoryValue = $inventoryValue * 0.98; // Simulate 2% decrease for demo
        $inventoryGrowth = $lastMonthInventoryValue > 0
            ? round((($inventoryValue - $lastMonthInventoryValue) / $lastMonthInventoryValue) * 100, 1)
            : 0;

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
                'growth' => $ordersGrowth,
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

    private function getSalesAnalytics($domain = null)
    {
        // Last 7 days sales data
        $salesData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            
            $salesQuery = Sale::where('payment_status', 'paid')
                ->whereDate('created_at', $date);
            if ($domain) {
                $salesQuery->where('domain', $domain);
            }
            
            $sales = $salesQuery->sum('grand_total') ?? 0;

            $transactionQuery = Sale::where('payment_status', 'paid')
                ->whereDate('created_at', $date);
            if ($domain) {
                $transactionQuery->where('domain', $domain);
            }

            $salesData[] = [
                'date' => $date->format('M d'),
                'sales' => $sales,
                'transactions' => $transactionQuery->count()
            ];
        }

        // Payment methods distribution
        $paymentMethodsQuery = Sale::where('payment_status', 'paid')
            ->where('created_at', '>=', now()->subDays(7));
        if ($domain) {
            $paymentMethodsQuery->where('domain', $domain);
        }
        
        $paymentMethods = $paymentMethodsQuery
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

    private function getInventoryAlerts($location, $domain = null)
    {
        if ($domain) {
            // Domain-specific view: use the provided location
            $inventoryReport = $this->inventoryService->getInventoryReport($location);

            // Low stock products with proper inventory data
            $lowStockProducts = $inventoryReport['low_stock_products'] ?? [];
            
            // Transform low stock products to include current stock info
            $lowStockProducts = $lowStockProducts->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'SKU' => $product->SKU,
                    'current_stock' => $product->current_stock,
                    'min_stock_level' => $product->min_stock_level,
                ];
            });

            // Out of stock products with proper inventory data
            $outOfStockQuery = Product::outOfStock($location)
                ->with(['inventories' => function ($query) use ($location) {
                    if ($location) {
                        $query->where('location_id', $location->id);
                    }
                }])
                ->where('domain', $domain);
            
            $outOfStockProducts = $outOfStockQuery
                ->limit(5)
                ->get()
                ->map(function ($product) use ($location) {
                    $inventory = $product->inventories->where('location_id', $location->id ?? null)->first();
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'SKU' => $product->SKU,
                        'current_stock' => $inventory ? $inventory->quantity_available : 0,
                        'min_stock_level' => $inventory ? $inventory->getEffectiveReorderLevel() : $product->reorder_level,
                    ];
                });

            // Recent stock movements
            $recentMovementsQuery = InventoryMovement::with(['product', 'user'])
                ->where('location_id', $location->id ?? null)
                ->where('domain', $domain);
            
            $recentMovements = $recentMovementsQuery
                ->latest()
                ->limit(5)
                ->get();
        } else {
            // Global view: show inventory alerts from ALL domains
            $lowStockProducts = collect();
            
            // Get out of stock products from all domains
            $outOfStockProducts = Product::whereHas('inventories', function ($query) {
                $query->where('quantity_available', '<=', 0);
            })
            ->with(['inventories' => function ($query) {
                $query->where('quantity_available', '<=', 0);
            }])
            ->limit(5)
            ->get()
            ->map(function ($product) {
                $inventory = $product->inventories->where('quantity_available', '<=', 0)->first();
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'SKU' => $product->SKU,
                    'current_stock' => $inventory ? $inventory->quantity_available : 0,
                    'min_stock_level' => $product->reorder_level,
                ];
            });

            // Recent stock movements from all domains
            $recentMovements = InventoryMovement::with(['product', 'user'])
                ->latest()
                ->limit(5)
                ->get();
        }

        return [
            'low_stock_count' => count($lowStockProducts),
            'out_of_stock_count' => $outOfStockProducts->count(),
            'low_stock_products' => $lowStockProducts->take(5),
            'out_of_stock_products' => $outOfStockProducts,
            'recent_movements' => $recentMovements
        ];
    }

    private function getCustomerInsights($domain = null)
    {
        $customerQuery = Customer::query();
        if ($domain) {
            $customerQuery->where('domain', $domain);
        }
        
        $totalCustomers = $customerQuery->count();
        
        $loyaltyQuery = Customer::whereNotNull('loyalty_points');
        if ($domain) {
            $loyaltyQuery->where('domain', $domain);
        }
        $loyaltyMembers = $loyaltyQuery->count();
        
        $newCustomersQuery = Customer::whereDate('created_at', now()->startOfDay());
        if ($domain) {
            $newCustomersQuery->where('domain', $domain);
        }
        $newCustomersToday = $newCustomersQuery->count();

        // Customer tier distribution
        $tierDistributionQuery = DB::table('customers');
        if ($domain) {
            $tierDistributionQuery->where('domain', $domain);
        }
        
        $tierDistribution = $tierDistributionQuery
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

    private function getRecentTransactions($domain = null)
    {
        $transactionsQuery = Sale::with(['customer', 'saleItems.product'])
            ->where('payment_status', 'paid');
        
        if ($domain) {
            $transactionsQuery->where('domain', $domain);
        }
        
        return $transactionsQuery
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

    private function getTopProducts($location, $domain = null)
    {
        $topProductsQuery = InventoryMovement::where('movement_type', 'sale')
            ->where('created_at', '>=', now()->subDays(7));
        
        if ($domain) {
            // Domain-specific view: filter by location and domain
            $topProductsQuery->where('location_id', $location->id ?? null)
                           ->where('domain', $domain);
        } else {
            // Global view: show top products from ALL domains (no location filter)
            // Keep domain filtering for now, but could be removed to show cross-domain products
        }
        
        return $topProductsQuery
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

    private function getOperationalStatus($domain = null)
    {
        // Try to get active users from sessions table, fallback to 1 if table doesn't exist
        try {
            $activeUsers = DB::table('sessions')
                ->where('last_activity', '>', now()->subMinutes(30)->timestamp)
                ->count();
        } catch (\Exception $e) {
            $activeUsers = 1; // Fallback value
        }

        $voidsQuery = Sale::where('payment_status', 'voided')
            ->whereDate('created_at', now()->startOfDay());
        
        if ($domain) {
            $voidsQuery->where('domain', $domain);
        }
        
        $voidsToday = $voidsQuery->count();

        return [
            'active_users' => $activeUsers,
            'voids_today' => $voidsToday,
            'system_status' => 'online'
        ];
    }

    private function getTierColor($tier)
    {
        return match ($tier) {
            'bronze' => '#CD7F32',
            'silver' => '#C0C0C0',
            'gold' => '#FFD700',
            'platinum' => '#E5E4E2',
            default => '#CD7F32'
        };
    }
}