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
        $customers = Customer::filters($request->all())
            ->latest()
            ->paginate(15);

        return Inertia::render('Customers/Index', [
            'items' => CustomerResource::collection($customers)
        ]);
    }

    public function index(Request $request)
    {
        $customers = Customer::filters($request->all())
            ->paginate($request->get('per_page', 10));

        return CustomerResource::collection($customers);
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');

        if (empty($query)) {
            return response()->json([]);
        }

        $customers = Customer::search($query)
            ->limit(10)
            ->get()
            ->map(fn($customer) => $this->formatCustomerResponse($customer));

        return response()->json($customers);
    }

    public function getTierOptions()
    {
        $tiers = LoyaltyTier::active()->ordered()->get(['name', 'display_name']);

        return response()->json(
            $tiers->map(fn($tier) => [
                'value' => $tier->name,
                'label' => $tier->display_name,
            ])
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validateCustomer($request);

        if ($validated['enroll_in_loyalty'] ?? false) {
            $validated = array_merge($validated, Customer::defaultLoyaltyData());
        }
        unset($validated['enroll_in_loyalty']);

        $customer = Customer::create($validated);

        return response()->json([
            'success' => true,
            'customer' => $this->formatCustomerResponse($customer),
            'message' => 'Customer created successfully'
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $this->validateCustomer($request, $customer->id);

        if (($validated['enroll_in_loyalty'] ?? false) && is_null($customer->loyalty_points)) {
            $validated = array_merge($validated, Customer::defaultLoyaltyData());
        }
        unset($validated['enroll_in_loyalty']);

        $customer->update($validated);

        return response()->json([
            'success' => true,
            'customer' => $this->formatCustomerResponse($customer->fresh()),
            'message' => 'Customer updated successfully'
        ]);
    }

    public function show(Customer $customer)
    {
        return response()->json(
            array_merge($this->formatCustomerResponse($customer), [
                'recent_purchases' => $customer->sales()
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'grand_total', 'transaction_date']),
            ])
        );
    }

    /**
     * Shared validator
     */
    private function validateCustomer(Request $request, $ignoreId = null)
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|unique:customers,email' . ($ignoreId ? ",$ignoreId" : ''),
            'address' => 'nullable|string|max:500',
            'date_of_birth' => 'nullable|date',
            'enroll_in_loyalty' => 'boolean'
        ]);
    }

    /**
     * Unified customer response formatter
     */
    private function formatCustomerResponse(Customer $customer): array
    {
        $tierInfo = $customer->getTierInfo();

        return array_merge($customer->toArray(), [
            'tier' => $customer->tier ?? 'bronze',
            'tier_info' => $tierInfo,
            'display_text' => $customer->name . ($customer->phone ? " ({$customer->phone})" : ''),
        ]);
    }
}
