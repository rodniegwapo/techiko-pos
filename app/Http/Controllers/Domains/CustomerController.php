<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        $query = Customer::query()
            ->with(['loyaltyTier'])
            ->when($domain, function ($query) use ($domain) {
                return $query->where('domain', $domain->name_slug);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->when($request->loyalty_tier, function ($query, $tier) {
                return $query->whereHas('loyaltyTier', function ($q) use ($tier) {
                    $q->where('name', $tier);
                });
            });

        $customers = $query->latest()->paginate(15);

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Store a newly created customer for the domain.
     */
    public function store(Request $request, Domain $domain = null)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:customers,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'loyalty_tier_id' => 'nullable|exists:loyalty_tiers,id',
        ]);

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }
        $customer = Customer::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'customer' => $customer->load('loyaltyTier')
        ], 201);
    }

    /**
     * Update the specified customer for the domain.
     */
    public function update(Request $request, Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'loyalty_tier_id' => 'nullable|exists:loyalty_tiers,id',
        ]);

        $customer->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
            'customer' => $customer->load('loyaltyTier')
        ]);
    }

    /**
     * Remove the specified customer from the domain.
     */
    public function destroy(Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        // Check if customer has sales
        if ($customer->sales()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete customer with existing sales'
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully'
        ]);
    }
}
