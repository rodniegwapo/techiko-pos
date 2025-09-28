<?php

namespace App\Http\Controllers;

use App\Http\Resources\LoyaltyTierResource;
use App\Models\LoyaltyTier;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LoyaltyTierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = LoyaltyTier::query()
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('status'), function ($query, $status) {
                $isActive = $status === 'active';
                return $query->where('is_active', $isActive);
            });

        // Always order by sort_order
        $query->ordered();

        // Add pagination support
        $tiers = $query->paginate($request->get('per_page', 10));

        // Check if this is an API request
        if ($request->expectsJson() || $request->is('api/*')) {
            return LoyaltyTierResource::collection($tiers);
        }

        // Return Inertia render for web requests
        return Inertia::render('LoyaltyTiers/Index', [
            'items' => LoyaltyTierResource::collection($tiers)
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:loyalty_tiers,name',
            'display_name' => 'required|string',
            'multiplier' => 'required|numeric|min:1|max:10',
            'spending_threshold' => 'required|numeric|min:0',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string',
            'sort_order' => 'required|integer|min:1',
            'is_active' => 'sometimes|boolean'
        ]);

        // Set default value for is_active if not provided
        $validated['is_active'] = $validated['is_active'] ?? true;

        $tier = LoyaltyTier::create($validated);
        return response()->json($tier, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(LoyaltyTier $tier)
    {
        return response()->json($tier);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LoyaltyTier $tier)
    {
        $validated = $request->validate([
            'display_name' => 'sometimes|required|string',
            'multiplier' => 'sometimes|required|numeric|min:1|max:10',
            'spending_threshold' => 'sometimes|required|numeric|min:0',
            'color' => 'sometimes|required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string',
            'sort_order' => 'sometimes|required|integer|min:1',
            'is_active' => 'sometimes|boolean'
        ]);

        $oldThreshold = $tier->spending_threshold;
        $tier->update($validated);
        
        // If spending threshold changed, recalculate customer tiers
        if (isset($validated['spending_threshold']) && $oldThreshold != $validated['spending_threshold']) {
            $this->recalculateCustomerTiers();
        }
        
        return response()->json($tier->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LoyaltyTier $tier)
    {
        // Don't allow deleting if customers are using this tier
        $customerCount = Customer::where('tier', $tier->name)->count();
        
        if ($customerCount > 0) {
            return response()->json([
                'error' => "Cannot delete tier. {$customerCount} customers are currently using this tier."
            ], 400);
        }

        $tier->delete();
        return response()->json(['message' => 'Tier deleted successfully']);
    }

    /**
     * Recalculate all customer tiers based on new thresholds
     */
    private function recalculateCustomerTiers()
    {
        $customers = Customer::whereNotNull('lifetime_spent')->get();
        
        foreach ($customers as $customer) {
            $customer->updateTierBasedOnSpending();
        }
    }
}
