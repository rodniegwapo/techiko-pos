<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\LoyaltyTier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function webIndex(Request $request)
    {
        $customers = Customer::query()
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('loyalty_status'), function ($query, $status) {
                if ($status === 'enrolled') {
                    return $query->whereNotNull('loyalty_points');
                } elseif ($status === 'not_enrolled') {
                    return $query->whereNull('loyalty_points');
                }
                return $query;
            })
            ->when($request->input('tier'), function ($query, $tier) {
                return $query->where('tier', $tier);
            })
            ->when($request->input('date_range'), function ($query, $range) {
                $now = now();
                switch ($range) {
                    case '7_days':
                        return $query->where('created_at', '>=', $now->subDays(7));
                    case '30_days':
                        return $query->where('created_at', '>=', $now->subDays(30));
                    case '3_months':
                        return $query->where('created_at', '>=', $now->subMonths(3));
                    case '1_year':
                        return $query->where('created_at', '>=', $now->subYear());
                    default:
                        return $query;
                }
            })
            ->latest()
            ->paginate(15);

        return Inertia::render('Customers/Index', [
            'items' => CustomerResource::collection($customers)
        ]);
    }

    public function index(Request $request)
    {
        $query = Customer::query()
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('tier'), function ($query, $tier) {
                return $query->where('tier', $tier);
            });

        $customers = $query->paginate($request->get('per_page', 10));

        return CustomerResource::collection($customers);
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');
        
        if (empty($query)) {
            return response()->json([]);
        }

        $customers = Customer::query()
            ->search($query)
            ->limit(10)
            ->get()
            ->map(function ($customer) {
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
                    'display_text' => $customer->name . ($customer->phone ? " ({$customer->phone})" : ''),
                ];
            });

        return response()->json($customers);
    }

    public function getTierOptions()
    {
        $tiers = LoyaltyTier::active()->ordered()->get(['name', 'display_name']);
        
        return response()->json($tiers->map(function ($tier) {
            return [
                'value' => $tier->name,
                'label' => $tier->display_name,
            ];
        }));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|unique:customers,email',
            'address' => 'nullable|string|max:500',
            'date_of_birth' => 'nullable|date',
            'enroll_in_loyalty' => 'boolean'
        ]);

        // Initialize loyalty fields if enrolling
        if ($validated['enroll_in_loyalty'] ?? false) {
            $validated['loyalty_points'] = 0;
            $validated['tier'] = 'bronze';
            $validated['lifetime_spent'] = 0;
            $validated['total_purchases'] = 0;
            $validated['tier_achieved_date'] = now();
        }

        // Remove the enrollment flag as it's not a database field
        unset($validated['enroll_in_loyalty']);

        $customer = Customer::create($validated);
        $tierInfo = $customer->getTierInfo();
        
        return response()->json([
            'success' => true,
            'customer' => array_merge($customer->toArray(), [
                'tier_info' => $tierInfo,
                'display_text' => $customer->name . ($customer->phone ? " ({$customer->phone})" : ''),
            ]),
            'message' => 'Customer created successfully'
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|unique:customers,email,' . $customer->id,
            'address' => 'nullable|string|max:500',
            'date_of_birth' => 'nullable|date',
            'enroll_in_loyalty' => 'boolean'
        ]);

        // Handle loyalty enrollment for existing customers
        if (($validated['enroll_in_loyalty'] ?? false) && $customer->loyalty_points === null) {
            $validated['loyalty_points'] = 0;
            $validated['tier'] = 'bronze';
            $validated['lifetime_spent'] = 0;
            $validated['total_purchases'] = 0;
            $validated['tier_achieved_date'] = now();
        }

        // Remove the enrollment flag as it's not a database field
        unset($validated['enroll_in_loyalty']);

        $customer->update($validated);
        $tierInfo = $customer->getTierInfo();

        return response()->json([
            'success' => true,
            'customer' => array_merge($customer->fresh()->toArray(), [
                'tier_info' => $tierInfo,
                'display_text' => $customer->name . ($customer->phone ? " ({$customer->phone})" : ''),
            ]),
            'message' => 'Customer updated successfully'
        ]);
    }

    public function show(Customer $customer)
    {
        $tierInfo = $customer->getTierInfo();
        
        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'loyalty_points' => $customer->loyalty_points,
            'tier' => $customer->tier ?? 'bronze',
            'tier_info' => $tierInfo,
            'lifetime_spent' => $customer->lifetime_spent,
            'total_purchases' => $customer->total_purchases,
            'recent_purchases' => $customer->sales()
                ->latest()
                ->limit(5)
                ->get(['id', 'grand_total', 'transaction_date']),
        ]);
    }
}
