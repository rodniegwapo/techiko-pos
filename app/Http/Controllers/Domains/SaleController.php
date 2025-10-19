<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Category;
use App\Models\Product\Discount;
use App\Models\MandatoryDiscount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SaleController extends Controller
{
    /**
     * Display the sales page for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        // Get domain-specific data (if domain exists)
        if ($domain) {
            $categories = Category::where('domain', $domain->name_slug)->get();
            $discounts = Discount::where('domain', $domain->name_slug)->get();
            $mandatoryDiscounts = MandatoryDiscount::where('domain', $domain->name_slug)
                ->where('is_active', true)->get();
        } else {
            // Fallback to global data if no domain
            $categories = Category::all();
            $discounts = Discount::all();
            $mandatoryDiscounts = MandatoryDiscount::where('is_active', true)->get();
        }

        return Inertia::render('Sales/Index', [
            'categories' => $categories,
            'discounts' => $discounts,
            'mandatoryDiscounts' => $mandatoryDiscounts,
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Get products for the domain.
     */
    public function products(Request $request, Domain $domain = null)
    {
        $query = \App\Models\Product\Product::query()
            ->with('category')
            ->when($domain, function ($query) use ($domain) {
                return $query->where('domain', $domain->name_slug);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->category, function ($query, $category) {
                return $query->whereHas('category', function ($q) use ($category) {
                    $q->where('name', $category);
                });
            });

        $products = $query->when(
            !$request->input('search') && !$request->input('category'),
            fn ($q) => $q->limit(30)
        )->get();

        return \App\Http\Resources\ProductResource::collection($products);
    }
}
