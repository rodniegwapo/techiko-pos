<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LoyaltyController extends Controller
{
    public function index()
    {
        return Inertia::render('Loyalty/Index', [
            'currentDomain' => null,
            'isGlobalView' => true,
        ]);
    }

    public function stats()
    {
        $stats = [
            'total_customers' => Customer::count(),
            'loyal_customers' => Customer::whereNotNull('loyalty_points')->where('loyalty_points', '>', 0)->count(),
            'total_points' => Customer::sum('loyalty_points'),
            'loyalty_revenue' => Customer::sum('lifetime_spent')
        ];

        return response()->json($stats);
    }

    public function customers(Request $request)
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('tier')) {
            $query->where('tier', $request->tier);
        }

        $customers = $query->paginate($request->get('per_page', 10));

        $data = $customers->getCollection()->map(function ($customer) {
            $tierInfo = $customer->getTierInfo();

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'loyalty_points' => $customer->loyalty_points,
                'tier' => $customer->tier ?? 'bronze',
                'tier_info' => $tierInfo,
                'lifetime_spent' => $customer->lifetime_spent,
                'total_purchases' => $customer->total_purchases,
                'tier_achieved_date' => $customer->tier_achieved_date,
            ];
        });

        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ]
        ]);
    }

    public function analytics()
    {
        // Tier distribution
        $tierStats = DB::table('customers')
            ->select(
                DB::raw('COALESCE(tier, "bronze") as tier'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('tier')
            ->get();

        $totalCustomers = Customer::count();
        $tierColors = [
            'bronze' => '#CD7F32',
            'silver' => '#C0C0C0',
            'gold' => '#FFD700',
            'platinum' => '#E5E4E2'
        ];

        $tierDistribution = $tierStats->map(function ($stat) use ($totalCustomers, $tierColors) {
            $percentage = $totalCustomers > 0 ? round(($stat->count / $totalCustomers) * 100, 1) : 0;

            return [
                'tier' => $stat->tier,
                'name' => ucfirst($stat->tier),
                'count' => (int) $stat->count,
                'percentage' => $percentage,
                'color' => $tierColors[$stat->tier] ?? $tierColors['bronze']
            ];
        });

        // Points analytics
        $totalPointsIssued = Customer::sum('loyalty_points') ?? 0;
        $totalPointsRedeemed = 0; // We don't have redemption tracking yet
        $activePoints = $totalPointsIssued - $totalPointsRedeemed;

        // Sales analytics
        $loyaltyMemberSales = Sale::whereNotNull('customer_id')
            ->where('payment_status', 'paid')
            ->sum('grand_total') ?? 0;

        $nonMemberSales = Sale::whereNull('customer_id')
            ->where('payment_status', 'paid')
            ->sum('grand_total') ?? 0;

        $totalSales = $loyaltyMemberSales + $nonMemberSales;

        // Average transaction amounts
        $avgMemberTransaction = Sale::whereNotNull('customer_id')
            ->where('payment_status', 'paid')
            ->avg('grand_total') ?? 0;

        $avgNonMemberTransaction = Sale::whereNull('customer_id')
            ->where('payment_status', 'paid')
            ->avg('grand_total') ?? 0;

        // Recent loyalty activity (from sales with customers)
        $recentActivity = Sale::with('customer')
            ->whereNotNull('customer_id')
            ->where('payment_status', 'paid')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($sale) {
                if (!$sale->customer) return null;

                $pointsEarned = $sale->customer->calculatePointsForPurchase($sale->grand_total ?? 0);

                return [
                    'id' => $sale->id,
                    'customer_name' => $sale->customer->name,
                    'action' => 'Purchase reward',
                    'points' => (int) $pointsEarned,
                    'created_at' => $sale->transaction_date ?? $sale->created_at
                ];
            })
            ->filter()
            ->values();

        // Comprehensive stats for analytics
        $stats = [
            'total_points_issued' => (int) $totalPointsIssued,
            'total_points_redeemed' => (int) $totalPointsRedeemed,
            'active_points' => (int) $activePoints,
            'loyalty_member_sales' => (float) $loyaltyMemberSales,
            'non_member_sales' => (float) $nonMemberSales,
            'total_sales' => (float) $totalSales,
            'avg_member_transaction' => (float) $avgMemberTransaction,
            'avg_non_member_transaction' => (float) $avgNonMemberTransaction,
        ];

        return response()->json([
            'tier_distribution' => $tierDistribution,
            'recent_activity' => $recentActivity,
            'stats' => $stats
        ]);
    }

    public function adjustPoints(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'type' => 'required|in:add,subtract,set',
            'amount' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:255'
        ]);

        DB::transaction(function () use ($customer, $validated) {
            $currentPoints = $customer->loyalty_points ?? 0;

            switch ($validated['type']) {
                case 'add':
                    $newPoints = $currentPoints + $validated['amount'];
                    break;
                case 'subtract':
                    $newPoints = max(0, $currentPoints - $validated['amount']);
                    break;
                case 'set':
                    $newPoints = $validated['amount'];
                    break;
            }

            $customer->update(['loyalty_points' => $newPoints]);

            // Log the adjustment (you can create a separate table for this if needed)
            \Log::info('Loyalty points adjusted', [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'previous_points' => $currentPoints,
                'new_points' => $newPoints,
                'reason' => $validated['reason'],
                'adjusted_by' => auth()->user()->name ?? 'System'
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Points adjusted successfully',
            'new_points' => $customer->fresh()->loyalty_points
        ]);
    }
}
