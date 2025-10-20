<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LoyaltyController extends Controller
{
    public function index(Request $request, Domain $domain)
    {
        return Inertia::render('Loyalty/Index', [
            'currentDomain' => $domain->name_slug,
            'isGlobalView' => false,
        ]);
    }

    public function stats(Request $request, Domain $domain)
    {
        $q = Customer::where('domain', $domain->name_slug);

        $stats = [
            'total_customers' => (clone $q)->count(),
            'loyal_customers' => (clone $q)->whereNotNull('loyalty_points')->where('loyalty_points', '>', 0)->count(),
            'total_points' => (clone $q)->sum('loyalty_points'),
            'loyalty_revenue' => (clone $q)->sum('lifetime_spent'),
        ];

        return response()->json($stats);
    }

    public function customers(Request $request, Domain $domain)
    {
        $q = Customer::where('domain', $domain->name_slug);

        if ($request->filled('search')) {
            $q->search($request->search);
        }
        if ($request->filled('tier')) {
            $q->where('tier', $request->tier);
        }

        $customers = $q->paginate($request->get('per_page', 10));

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

    public function analytics(Request $request, Domain $domain)
    {
        $tierStats = DB::table('customers')
            ->where('domain', $domain->name_slug)
            ->select(DB::raw('COALESCE(tier, "bronze") as tier'), DB::raw('COUNT(*) as count'))
            ->groupBy('tier')
            ->get();

        $totalCustomers = Customer::where('domain', $domain->name_slug)->count();
        $tierColors = ['bronze' => '#CD7F32','silver' => '#C0C0C0','gold' => '#FFD700','platinum' => '#E5E4E2'];

        $tierDistribution = $tierStats->map(function ($stat) use ($totalCustomers, $tierColors) {
            $percentage = $totalCustomers > 0 ? round(($stat->count / $totalCustomers) * 100, 1) : 0;
            return [
                'tier' => $stat->tier,
                'name' => ucfirst($stat->tier),
                'count' => (int) $stat->count,
                'percentage' => $percentage,
                'color' => $tierColors[$stat->tier] ?? $tierColors['bronze'],
            ];
        });

        $totalPointsIssued = Customer::where('domain', $domain->name_slug)->sum('loyalty_points') ?? 0;
        $totalPointsRedeemed = 0;
        $activePoints = $totalPointsIssued - $totalPointsRedeemed;

        $loyaltyMemberSales = Sale::whereNotNull('customer_id')->where('payment_status', 'paid')->sum('grand_total') ?? 0;
        $nonMemberSales = Sale::whereNull('customer_id')->where('payment_status', 'paid')->sum('grand_total') ?? 0;

        $avgMemberTransaction = Sale::whereNotNull('customer_id')->where('payment_status', 'paid')->avg('grand_total') ?? 0;
        $avgNonMemberTransaction = Sale::whereNull('customer_id')->where('payment_status', 'paid')->avg('grand_total') ?? 0;

        return response()->json([
            'tier_distribution' => $tierDistribution,
            'recent_activity' => [],
            'stats' => [
                'total_points_issued' => (int) $totalPointsIssued,
                'total_points_redeemed' => (int) $totalPointsRedeemed,
                'active_points' => (int) $activePoints,
                'loyalty_member_sales' => (float) $loyaltyMemberSales,
                'non_member_sales' => (float) $nonMemberSales,
                'total_sales' => (float) ($loyaltyMemberSales + $nonMemberSales),
                'avg_member_transaction' => (float) $avgMemberTransaction,
                'avg_non_member_transaction' => (float) $avgNonMemberTransaction,
            ],
        ]);
    }

    public function adjustPoints(Request $request, Domain $domain, Customer $customer)
    {
        if ($customer->domain !== $domain->name_slug) {
            return response()->json(['message' => 'Customer does not belong to this domain'], 403);
        }

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
        });

        return response()->json([
            'success' => true,
            'message' => 'Points adjusted successfully',
            'new_points' => $customer->fresh()->loyalty_points
        ]);
    }
}



