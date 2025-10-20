<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyTierResource;
use App\Models\Domain;
use App\Models\LoyaltyTier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LoyaltyTierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Domain $domain)
    {
        $query = LoyaltyTier::query()
            ->where('domain', $domain->name_slug)
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
    public function store(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:loyalty_tiers,name',
            'display_name' => 'required|string|max:255',
            'multiplier' => 'required|numeric|min:0.1|max:10',
            'spending_threshold' => 'required|numeric|min:0',
            'color' => 'required|string|max:7',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $validated['domain'] = $domain->name_slug;

        $tier = LoyaltyTier::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tier created successfully',
            'data' => new LoyaltyTierResource($tier)
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Domain $domain, LoyaltyTier $tier)
    {
        // Ensure tier belongs to this domain
        if ($tier->domain !== $domain->name_slug) {
            return response()->json(['message' => 'Tier does not belong to this domain'], 403);
        }

        return new LoyaltyTierResource($tier);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Domain $domain, LoyaltyTier $tier)
    {
        // Ensure tier belongs to this domain
        if ($tier->domain !== $domain->name_slug) {
            return response()->json(['message' => 'Tier does not belong to this domain'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:loyalty_tiers,name,' . $tier->id,
            'display_name' => 'required|string|max:255',
            'multiplier' => 'required|numeric|min:0.1|max:10',
            'spending_threshold' => 'required|numeric|min:0',
            'color' => 'required|string|max:7',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $tier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tier updated successfully',
            'data' => new LoyaltyTierResource($tier)
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Domain $domain, LoyaltyTier $tier)
    {
        // Ensure tier belongs to this domain
        if ($tier->domain !== $domain->name_slug) {
            return response()->json(['message' => 'Tier does not belong to this domain'], 403);
        }

        // Check if tier is being used by customers
        $customerCount = \App\Models\Customer::where('tier', $tier->name)
            ->where('domain', $domain->name_slug)
            ->count();

        if ($customerCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete tier. {$customerCount} customers are currently using this tier."
            ], 422);
        }

        $tier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tier deleted successfully'
        ]);
    }
}
