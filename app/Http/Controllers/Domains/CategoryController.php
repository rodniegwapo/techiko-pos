<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use App\Helpers;
use App\Traits\LocationCategoryScoping;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Inertia\Inertia;

class CategoryController extends Controller
{
    use LocationCategoryScoping;
    /**
     * Display a listing of categories for the domain.
     */
    public function index(Request $request, ?Domain $domain = null)
    {
        // For category management, show all categories for the domain regardless of location
        // Location filtering should only apply to product/inventory views, not category management
        $query = $domain 
            ? Category::where('domain', $domain->name_slug)->withCount('products')
            : Category::query()->withCount('products');
        
        $query = $query->when($request->input('search'), function ($q, $search) {
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
    public function store(Request $request, ?Domain $domain = null)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule($domain)],
            'description' => 'nullable|string',
        ], $this->uniqueNameMessage());

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }
        
        // Create category (no direct location binding)
        $category = Category::create($validated);

        return redirect()->back()->with('success', 'Category created successfully');
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
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule($domain)->ignore($category->id)],
            'description' => 'nullable|string',
        ], $this->uniqueNameMessage());

        $category->update($validated);

        return redirect()->back()->with('success', 'Category updated successfully');
    }

    /** Category names are unique within an organization, or products can't tell them apart. */
    private function uniqueNameRule(?Domain $domain): Unique
    {
        return Rule::unique('categories', 'name')->where('domain', $domain?->name_slug);
    }

    /** @return array<string, string> */
    private function uniqueNameMessage(): array
    {
        return ['name.unique' => 'A category with this name already exists.'];
    }

    /**
     * Remove the specified category from the domain.
     */
    public function destroy(Request $request, Domain $domain, Category $category)
    {
        // Ensure category belongs to this domain
        if ($category->domain !== $domain->name_slug) {
            abort(403, 'Category does not belong to this domain');
        }

        // Check if category has products
        $productCount = \App\Models\Product\Product::where('category_id', $category->id)->count();

        if ($productCount > 0) {
            return redirect()->back()->with('error', "Cannot delete category with {$productCount} existing products. Please move or delete the products first.");
        }

        try {
            $category->delete();
            return redirect()->back()->with('success', 'Category deleted successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete category: ' . $e->getMessage());
        }
    }
}
