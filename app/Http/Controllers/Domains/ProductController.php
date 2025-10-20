<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Domain;
use App\Models\Product\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    /**
     * Display a listing of products for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        $query = Product::query()
            ->with('category')
            ->when($domain, function ($query) use ($domain) {
                return $query->where('domain', $domain->name_slug);
            })
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->when($request->category, function ($query, $category) {
                return $query->whereHas('category', function ($q) use ($category) {
                    $q->where('name', $category);
                });
            });

        $products = $query->latest()->paginate(15);

        return Inertia::render('Products/Index', [
            // Frontend expects `items` with a paginated resource
            'items' => ProductResource::collection($products),
            'currentDomain' => $domain,
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
        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'product' => new ProductResource($product->load('category'))
        ], 201);
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

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'product' => new ProductResource($product->load('category'))
        ]);
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

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }
}
