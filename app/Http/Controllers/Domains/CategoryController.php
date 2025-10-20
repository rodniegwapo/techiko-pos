<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        $query = Category::query()
            ->withCount('products')
            // Filter by current domain if present
            ->when($domain, function ($q) use ($domain) {
                return $q->forDomain($domain->name_slug);
            })
            // Use Searchable trait on Category
            ->when($request->input('search'), function ($q, $search) {
                return $q->search($search);
            });

        $items = $query->latest()->paginate($request?->data['per_page'] ?? 15);

        return Inertia::render('Categories/Index', [
            'items' => CategoryResource::collection($items),
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Store a newly created category for the domain.
     */
    public function store(Request $request, Domain $domain = null)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }
        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'category' => $category
        ], 201);
    }

    /**
     * Update the specified category for the domain.
     */
    public function update(Request $request, Domain $domain, Category $category)
    {
        // Ensure category belongs to this domain
        if ($category->domain !== $domain->name_slug) {
            abort(403, 'Category does not belong to this domain');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'category' => $category
        ]);
    }

    /**
     * Remove the specified category from the domain.
     */
    public function destroy(Domain $domain, Category $category)
    {
        // Ensure category belongs to this domain
        if ($category->domain !== $domain->name_slug) {
            abort(403, 'Category does not belong to this domain');
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with existing products'
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully'
        ]);
    }
}
