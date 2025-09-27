<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyTier;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
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

        return response()->json([
            'data' => $customers->items(),
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ]
        ]);
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
            'date_of_birth' => 'nullable|date',
        ]);

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
