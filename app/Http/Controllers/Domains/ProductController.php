<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Product\ProductSoldType;
use App\Support\ProductPayloadNormalizer;
use App\Traits\LocationCategoryScoping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProductController extends Controller
{
    use LocationCategoryScoping;

    /**
     * Resolve active location for the given domain and request.
     */
    private function resolveActiveLocation(Request $request, ?Domain $domain = null)
    {
        return Helpers::getActiveLocation($domain, $request->input('location_id'));
    }

    /**
     * Centralized validation for product data.
     */
    private function validatedData(Request $request, ?Product $product = null, ?Domain $domain = null): array
    {
        $productId = $product?->id;
        $domainSlug = $domain?->name_slug;

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sold_type' => ['required', 'string', 'max:255', 'exists:product_sold_types,name'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],

            'category_id' => ['required', 'exists:categories,id'],
            'SKU' => ['required', 'string', 'max:255', 'unique:products,SKU,' . $productId],
            'barcode' => ['required', 'string', 'max:255', 'unique:products,barcode,' . $productId],

            'representation_type' => ['nullable', 'string', 'in:image,color,text'],
            'representation' => ['nullable', 'string'],

            'track_inventory' => ['boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'unit_weight' => ['nullable', 'numeric', 'min:0'],

            'location_id' => ['nullable', 'exists:inventory_locations,id'],
        ], [], [
            'name' => 'product name',
            'sold_type' => 'sold type',
            'category_id' => 'category',
            'location_id' => 'location',
        ]);
    }

    /**
     * Validate product uniqueness within domain and location scope.
     */
    private function validateProductUniqueness(Request $request, ?Product $product = null, ?Domain $domain = null)
    {
        if (! $request->filled('location_id') || ! $domain) {
            return; // Skip if no location or domain context
        }

        $query = Product::where('domain', $domain->name_slug)
            ->whereHas('activeLocations', function ($q) use ($request) {
                $q->where('location_id', $request->input('location_id'));
            });

        if ($product) {
            $query->where('id', '!=', $product->id);
        }

        $existingProduct = $query->first();

        if ($existingProduct) {
            throw ValidationException::withMessages([
                'name' => ['A product with this name already exists in the selected location for this domain.'],
            ]);
        }
    }

    /**
     * Build the base product query scoped by domain (full catalog; not filtered by store).
     */
    private function buildProductQuery(Request $request, Domain $domain): Builder
    {
        return Product::query()
            ->with('category')
            ->where('domain', $domain->name_slug)
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->when($request->category, function ($query, $category) {
                return $query->whereHas('category', function ($q) use ($category) {
                    $q->where('name', $category);
                });
            });
    }

    /**
     * Build categories query derived from location if present; otherwise domain-scoped.
     */
    private function buildCategoriesQuery(Domain $domain)
    {
        return Category::where('domain', $domain->name_slug);
    }

    /**
     * Standard response for products index.
     */
    private function respondWithIndex($products, $categoriesQuery, $location)
    {
        return Inertia::render('Products/Index', [
            'items' => ProductResource::collection($products),
            'categories' => $categoriesQuery->get(),
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => false,
            'currentLocation' => $location,
        ]);
    }

    /**
     * Display a listing of products for the domain.
     */
    public function index(Request $request, ?Domain $domain = null)
    {
        $location = $this->resolveActiveLocation($request, $domain);
        $query = $this->buildProductQuery($request, $domain)
            ->when($location, function ($q) use ($location) {
                $q->with([
                    'inventories' => fn($iq) => $iq->where('location_id', $location->id),
                ]);
            })
            ->latest();

        $products = $query->paginate(15);

        $categoriesQuery = $this->buildCategoriesQuery($domain);

        return $this->respondWithIndex($products, $categoriesQuery, $location);
    }

    /**
     * Store a newly created product for the domain.
     */
    public function store(Request $request, ?Domain $domain = null)
    {
        $this->validateProductUniqueness($request, null, $domain);
        $validated = ProductPayloadNormalizer::applyRepresentationAndCostDefaults(
            $this->validatedData($request, null, $domain),
        );

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }

        $product = Product::create($validated);

        $location = $this->resolveActiveLocation($request, $domain)
            ?: ($request->location_id ? InventoryLocation::find($request->location_id) : null);

        if ($location) {
            $product->addToLocation($location, true);
        }

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

        $this->validateProductUniqueness($request, $product, $domain);
        $validated = ProductPayloadNormalizer::applyRepresentationAndCostDefaults(
            $this->validatedData($request, $product, $domain),
        );
        $product->update($validated);

        if ($request->filled('location_id')) {
            $location = InventoryLocation::find($request->input('location_id'));
            if ($location) {
                $product->addToLocation($location, true);
            }
        }

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

    /**
     * Show the form for creating a new product.
     */
    public function create(Request $request, Domain $domain)
    {
        $location = $this->resolveActiveLocation($request, $domain);
        $categoriesQuery = $this->buildCategoriesQuery($domain);

        return Inertia::render('Products/Create', [
            'categories' => $categoriesQuery->get(),
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => false,
            'currentLocation' => $location,
        ]);
    }

    /**
     * Show the form for editing the specified product.
     */
    public function edit(Request $request, Domain $domain, Product $product)
    {
        // Ensure product belongs to this domain
        if ($product->domain !== $domain->name_slug) {
            abort(403, 'Product does not belong to this domain');
        }

        $location = $this->resolveActiveLocation($request, $domain);
        $categoriesQuery = $this->buildCategoriesQuery($domain);

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => $categoriesQuery->get(),
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => false,
            'currentLocation' => $location,
        ]);
    }
}
