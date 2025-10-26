<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Domain;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Helpers;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    /**
     * Display a listing of products for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        $location = Helpers::getEffectiveLocation($domain, $request->input('location_id'));
        // Simple query - much cleaner!
        $query = Product::query()
            ->with('category')
            ->where('domain', $domain->name_slug)
            ->where('location_id', $location->id)
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->when($request->category, function ($query, $category) {
                return $query->whereHas('category', function ($q) use ($category) {
                    $q->where('name', $category);
                });
            });

        $products = $query->latest()->paginate(15);

        return Inertia::render('Products/Index', [
            'items' => ProductResource::collection($products),
            'categories' => Category::where('domain', $domain->name_slug)->where('location_id', $location->id)->get(),
            'sold_by_types' => \App\Models\Product\ProductSoldType::all(),
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Store a newly created product for the domain.
     */
    public function store(Request $request, Domain $domain = null)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sold_type' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'SKU' => 'nullable|string|max:255',
            'track_inventory' => 'boolean',
            'reorder_level' => 'nullable|numeric|min:0',
            'max_stock_level' => 'nullable|numeric|min:0',
            'unit_weight' => 'nullable|numeric|min:0',
        ]);

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }

        // Get effective location for the product
        $location = Helpers::getEffectiveLocation($domain, $request->input('location_id'));
        $validated['location_id'] = $location->id;

        $product = Product::create($validated);

        return redirect()->back()->with('success', 'Product created successfully');
    }

    /**
     * Update the specified product for the domain.
     */
    public function update(Request $request, Domain $domain, Product $product)
    {
        // Ensure product belongs to this domain
        if ($product->domain !== $domain->name_slug) {
            abort(403, 'Product does not belong to this domain');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sold_type' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'SKU' => 'nullable|string|max:255',
            'track_inventory' => 'boolean',
            'reorder_level' => 'nullable|numeric|min:0',
            'max_stock_level' => 'nullable|numeric|min:0',
            'unit_weight' => 'nullable|numeric|min:0',
        ]);

        $product->update($validated);

        return redirect()->back()->with('success', 'Product updated successfully');
    }

    /**
     * Remove the specified product from the domain.
     */
    public function destroy(Domain $domain, Product $product)
    {
        // Ensure product belongs to this domain
        if ($product->domain !== $domain->name_slug) {
            abort(403, 'Product does not belong to this domain');
        }

        $product->delete();

        return redirect()->back()->with('success', 'Product deleted successfully');
    }
}
