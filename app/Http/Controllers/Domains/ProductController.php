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
use App\Models\ProductInventory;
use App\Models\SharedProduct;
use App\Models\SharedProductSuggestion;
use App\Services\DomainSubscriptionService;
use App\Services\InventoryService;
use App\Support\BarcodeNormalizer;
use App\Support\ProductImageStorage;
use App\Support\ProductPayloadNormalizer;
use App\Traits\LocationCategoryScoping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProductController extends Controller
{
    use LocationCategoryScoping;

    public function __construct(
        private DomainSubscriptionService $subscriptionService,
        private InventoryService $inventoryService
    ) {}

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
        $barcodeRules = ['nullable', 'string', 'max:255'];
        if ($domainSlug) {
            $barcodeRules[] = Rule::unique('products', 'barcode')
                ->where(fn ($q) => $q->where('domain', $domainSlug))
                ->ignore($productId);
        } else {
            $barcodeRules[] = Rule::unique('products', 'barcode')->ignore($productId);
        }

        // SKUs are unique within an organization, like barcodes; another organization's SKU is free to use.
        $skuRule = Rule::unique('products', 'SKU')->ignore($productId);
        if ($domainSlug) {
            $skuRule->where(fn ($q) => $q->where('domain', $domainSlug));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sold_type' => ['required', 'string', 'max:255', 'exists:product_sold_types,name'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],

            'category_id' => [
                'nullable',
                $domainSlug
                    ? Rule::exists('categories', 'id')->where('domain', $domainSlug)
                    : 'exists:categories,id',
            ],
            'SKU' => ['nullable', 'string', 'max:255', $skuRule],
            'barcode' => $barcodeRules,

            'representation_type' => ['nullable', 'string', 'in:image,color,text'],
            'representation' => ['nullable', 'string'],
            'representation_image' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp,gif',
                'max:2048',
            ],

            'track_inventory' => ['boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'unit_weight' => ['nullable', 'numeric', 'min:0'],

            'location_id' => [
                'nullable',
                $domainSlug
                    ? Rule::exists('inventory_locations', 'id')->where('domain', $domainSlug)
                    : 'exists:inventory_locations,id',
            ],
        ], [
            'category_id.exists' => 'The selected category does not belong to this organization.',
            'location_id.exists' => 'The selected store does not belong to this organization.',
        ], [
            'name' => 'product name',
            'SKU' => 'SKU',
            'sold_type' => 'sold type',
            'category_id' => 'category',
            'location_id' => 'location',
            'representation_image' => 'product image',
        ]);

        if (array_key_exists('category_id', $validated) && $validated['category_id'] === '') {
            $validated['category_id'] = null;
        }
        if (array_key_exists('SKU', $validated)) {
            $trim = isset($validated['SKU']) ? trim((string) $validated['SKU']) : '';
            $validated['SKU'] = $trim === '' ? null : $trim;
        }
        // A missing barcode is NULL, not '': the (domain, barcode) unique index allows many NULLs
        // but only one '' per organization.
        $barcode = BarcodeNormalizer::normalize($validated['barcode'] ?? null);
        $validated['barcode'] = $barcode === '' ? null : $barcode;

        // reorder_level is NOT NULL; a cleared "Low stock level" means no threshold.
        if (array_key_exists('reorder_level', $validated) && $validated['reorder_level'] === null) {
            $validated['reorder_level'] = 0;
        }

        // Request-only fields — not columns on products
        unset($validated['location_id'], $validated['representation_image']);

        return $validated;
    }

    /**
     * When a tenant saves a product with a barcode not yet in the global shared catalog,
     * queue a pending suggestion for super review (snapshot only — no pricing).
     */
    private function queueSharedCatalogSuggestionIfNeeded(Request $request, Domain $domain, Product $product): void
    {
        $norm = BarcodeNormalizer::normalize((string) $product->barcode);
        if ($norm === '') {
            return;
        }

        // A shop's own code (GS1 in-store range, e.g. from "Generate") isn't a real product barcode.
        if (preg_match('/^2\d{12}$/', $norm)) {
            return;
        }

        if (SharedProduct::query()->where('barcode', $norm)->exists()) {
            return;
        }

        $alreadyPending = SharedProductSuggestion::query()
            ->pending()
            ->forDomain($domain->name_slug)
            ->where('barcode', $norm)
            ->exists();

        if ($alreadyPending) {
            return;
        }

        $categoryLabel = $product->category_id
            ? Category::find($product->category_id)?->name
            : null;

        SharedProductSuggestion::create([
            'domain' => $domain->name_slug,
            'barcode' => $norm,
            'snapshot' => [
                'name' => $product->name,
                'sold_type' => $product->sold_type,
                'category_label' => $categoryLabel,
            ],
            'submitted_product_id' => $product->id,
            'submitted_by' => $request->user()?->id,
            'status' => SharedProductSuggestion::STATUS_PENDING,
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
            ->where('name', $request->input('name'))
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
     * Build the base product query scoped by domain (optionally narrowed to a store in {@see index}).
     */
    private function buildProductQuery(Request $request, Domain $domain): Builder
    {
        return Product::query()
            ->with('category')
            ->where('domain', $domain->name_slug)
            ->when($request->search, fn ($q, $s) => $q->search($s))
            ->when($request->category, function ($query, $category) {
                return $query->whereHas('category', function ($q) use ($category) {
                    $q->where('name', $category);
                });
            })
            ->when($request->sold_type, fn ($q, $soldType) => $q->where('sold_type', $soldType))
            // Price and cost filters (number inputs) match the exact amount.
            ->when(is_numeric($request->price), fn ($q) => $q->where('price', (float) $request->price))
            ->when(is_numeric($request->cost), fn ($q) => $q->where('cost', (float) $request->cost))
            ->when(is_numeric($request->price_min), fn ($q) => $q->where('price', '>=', (float) $request->price_min))
            ->when(is_numeric($request->price_max), fn ($q) => $q->where('price', '<=', (float) $request->price_max))
            ->when(in_array($request->track_stock, ['yes', 'no'], true), fn ($q) => $q->where('track_inventory', $request->track_stock === 'yes'));
    }

    /** The store's low stock level: its own override, else the product's. */
    private const LOW_STOCK_LEVEL_SQL = 'COALESCE(product_inventory.location_reorder_level, products.reorder_level, 0)';

    /**
     * Narrow to tracked products that are in stock, low or out at the store. A product with no
     * stock row at the store counts as out of stock.
     */
    private function applyStockStatus(Builder $query, string $status, InventoryLocation $location): void
    {
        $query->where('track_inventory', true);
        $atStore = fn ($q) => $q->where('location_id', $location->id);

        match ($status) {
            'out' => $query->whereDoesntHave('inventories', fn ($q) => $atStore($q)->where('quantity_available', '>', 0)),
            'low' => $query->whereHas('inventories', fn ($q) => $atStore($q)
                ->where('quantity_available', '>', 0)
                ->whereRaw(self::LOW_STOCK_LEVEL_SQL.' > 0')
                ->whereRaw('quantity_available <= '.self::LOW_STOCK_LEVEL_SQL)),
            'in' => $query->whereHas('inventories', fn ($q) => $atStore($q)
                ->where('quantity_available', '>', 0)
                ->whereRaw('quantity_available > '.self::LOW_STOCK_LEVEL_SQL)),
            default => null,
        };
    }

    /** @return array{low: int, out: int} how many of the store's products are low or out of stock */
    private function stockCounts(Domain $domain, InventoryLocation $location): array
    {
        $atStore = fn () => Product::query()
            ->where('domain', $domain->name_slug)
            ->whereHas('activeLocations', fn ($q) => $q->where('inventory_locations.id', $location->id));

        $counts = [];
        foreach (['low', 'out'] as $status) {
            $query = $atStore();
            $this->applyStockStatus($query, $status, $location);
            $counts[$status] = $query->count();
        }

        return $counts;
    }

    /** Sort options for the products list; newest first unless asked otherwise. */
    private function applySort(Builder $query, ?string $sort, ?InventoryLocation $location): void
    {
        match ($sort) {
            'name' => $query->orderBy('name')->orderBy('id'),
            'price_asc' => $query->orderBy('price')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price')->orderBy('id'),
            'qty_asc' => $location
                ? $query->orderBy(
                    ProductInventory::query()
                        ->select('quantity_available')
                        ->whereColumn('product_inventory.product_id', 'products.id')
                        ->where('location_id', $location->id)
                        ->limit(1)
                )->orderBy('id')
                : $query->latest(),
            default => $query->latest(),
        };
    }

    /**
     * Categories for filters: at the active store only, or empty when no store context.
     */
    private function buildCategoriesQuery(Domain $domain, ?InventoryLocation $location)
    {
        if ($location) {
            return $this->getCategoriesForLocation($domain->name_slug, $location);
        }

        return Category::where('domain', $domain->name_slug)->whereRaw('0 = 1');
    }

    /**
     * JSON: domain products already active at another store, not yet active at the given store (attach flow).
     */
    public function assignable(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $location = $this->resolveLocationForDomainOrFail((int) $validated['location_id'], $domain);

        $query = Product::query()
            ->with('category')
            ->where('domain', $domain->name_slug)
            ->whereDoesntHave('activeLocations', function ($q) use ($location) {
                $q->where('inventory_locations.id', $location->id);
            })
            ->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('inventory_locations.id', '!=', $location->id);
            })
            ->when(
                filled($validated['search'] ?? null),
                fn ($q) => $q->search($validated['search'])
            )
            ->orderBy('name')
            ->limit(20);

        return response()->json([
            'data' => ProductResource::collection($query->get()),
        ]);
    }

    /**
     * Attach an existing catalog product to a store (pivot); optional zero inventory row.
     */
    public function attachLocation(Request $request, Domain $domain, Product $product): JsonResponse
    {
        if ($product->domain !== $domain->name_slug) {
            abort(403, 'Product does not belong to this organization.');
        }

        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
        ]);

        $location = $this->resolveLocationForDomainOrFail((int) $validated['location_id'], $domain);

        if ($product->isAvailableAt($location)) {
            return response()->json([
                'success' => true,
                'already_attached' => true,
            ]);
        }

        $this->assertNoConflictingProductNameAtLocation($product, $location);

        $product->addToLocation($location, true);
        $this->inventoryService->getOrCreateInventory($product, $location);

        return response()->json([
            'success' => true,
            'already_attached' => false,
        ]);
    }

    /**
     * @throws ModelNotFoundException
     */
    private function resolveLocationForDomainOrFail(int $locationId, Domain $domain): InventoryLocation
    {
        return InventoryLocation::query()
            ->whereKey($locationId)
            ->where('domain', $domain->name_slug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function assertNoConflictingProductNameAtLocation(Product $product, InventoryLocation $location): void
    {
        $exists = Product::query()
            ->where('domain', $product->domain)
            ->where('id', '!=', $product->id)
            ->where('name', $product->name)
            ->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('inventory_locations.id', $location->id);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'product_id' => [__('Another product with the same name is already assigned to this store.')],
            ]);
        }
    }

    /**
     * Standard response for products index.
     */
    private function respondWithIndex($products, $categoriesQuery, $location, Domain $domain, array $stockCounts = ['low' => 0, 'out' => 0])
    {
        return Inertia::render('Products/Index', [
            'items' => ProductResource::collection($products),
            'stockCounts' => $stockCounts,
            'categories' => $categoriesQuery->get(),
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => false,
            'currentLocation' => $location,
            'subscription' => $this->subscriptionService->subscriptionPropsForFrontend($domain),
        ]);
    }

    /**
     * Display a listing of products for the domain.
     */
    public function index(Request $request, ?Domain $domain = null)
    {
        $location = $this->resolveActiveLocation($request, $domain);
        $query = $this->buildProductQuery($request, $domain);

        if ($location) {
            $query->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('inventory_locations.id', $location->id);
            })
                ->with([
                    'inventories' => fn ($iq) => $iq->where('location_id', $location->id),
                ])
                // The delete dialog says how many stores carry the product.
                ->withCount(['activeLocations as store_count']);

            if (in_array($request->stock_status, ['in', 'low', 'out'], true)) {
                $this->applyStockStatus($query, $request->stock_status, $location);
            }
        } else {
            $query->whereRaw('0 = 1');
        }

        $this->applySort($query, $request->sort, $location);

        $products = $query->paginate(15);

        $categoriesQuery = $this->buildCategoriesQuery($domain, $location);

        return $this->respondWithIndex(
            $products,
            $categoriesQuery,
            $location,
            $domain,
            $location ? $this->stockCounts($domain, $location) : ['low' => 0, 'out' => 0],
        );
    }

    /**
     * Store a newly created product for the domain.
     */
    public function store(Request $request, ?Domain $domain = null)
    {
        $this->validateProductUniqueness($request, null, $domain);
        if ($domain) {
            $this->subscriptionService->assertCanCreateProduct($domain);
        }

        $validated = ProductPayloadNormalizer::applyRepresentationAndCostDefaults(
            $this->validatedData($request, null, $domain),
        );
        $validated = ProductPayloadNormalizer::applyUploadedRepresentationImage(
            $request,
            $validated,
            $domain?->name_slug,
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

        if ($domain) {
            $this->queueSharedCatalogSuggestionIfNeeded($request, $domain, $product);
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
        $validated = ProductPayloadNormalizer::applyUploadedRepresentationImage(
            $request,
            $validated,
            $domain->name_slug,
        );
        $product->update($validated);
        $product->refresh();

        if ($request->filled('location_id')) {
            $location = InventoryLocation::find($request->input('location_id'));
            if ($location) {
                $product->addToLocation($location, true);
            }
        }

        $this->queueSharedCatalogSuggestionIfNeeded($request, $domain, $product);

        // Back to the items list (in the same store) once the edit is saved.
        return redirect()
            ->route('domains.products.index', array_filter([
                'domain' => $domain->name_slug,
                'location_id' => $request->input('location_id') ?: $request->query('location_id'),
            ]))
            ->with('success', 'Product updated successfully');
    }

    /**
     * Remove the specified product from the domain.
     */
    public function destroy(Request $request, Domain $domain, Product $product)
    {
        // Ensure product belongs to this domain
        if ($product->domain !== $domain->name_slug) {
            abort(403, 'Product does not belong to this domain');
        }

        $validated = $request->validate([
            'scope' => ['nullable', 'in:store,all'],
            'location_id' => ['required_if:scope,store', 'nullable', 'integer'],
        ]);

        if (($validated['scope'] ?? 'all') === 'store') {
            return $this->removeFromStore($product, (int) $validated['location_id'], $domain);
        }

        $product->delete();

        return redirect()->back()->with('success', 'Product deleted successfully');
    }

    /**
     * Take a product off one store's list, leaving it in the organization and at its other stores.
     * Refused while the store still holds stock, so no stock drops out of the store's records.
     */
    private function removeFromStore(Product $product, int $locationId, Domain $domain)
    {
        $location = InventoryLocation::query()
            ->whereKey($locationId)
            ->where('domain', $domain->name_slug)
            ->firstOrFail();

        if (! $product->isAvailableAt($location)) {
            return redirect()->back()->with('error', "{$product->name} isn't in {$location->name}.");
        }

        // A product in no store can't be offered back under "Add existing to store", so the last
        // store can only be left by deleting the product everywhere.
        if (! $product->activeLocations()->where('inventory_locations.id', '!=', $location->id)->exists()) {
            return redirect()->back()->with(
                'error',
                "{$location->name} is the only store with {$product->name}. Delete it everywhere instead.",
            );
        }

        $onHand = (float) ProductInventory::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->value('quantity_on_hand');

        if ($product->track_inventory && $onHand > 0) {
            $quantity = rtrim(rtrim(number_format($onHand, 2, '.', ''), '0'), '.');

            return redirect()->back()->with(
                'error',
                "{$location->name} still has {$quantity} of {$product->name} in stock. Transfer it or adjust it to 0 first.",
            );
        }

        $product->removeFromLocation($location);

        return redirect()->back()->with('success', "{$product->name} was removed from {$location->name}.");
    }

    /**
     * Show the form for creating a new product.
     */
    public function create(Request $request, Domain $domain)
    {
        $location = $this->resolveActiveLocation($request, $domain);
        // All domain categories (same as category management), not only those with products at this store.
        $categories = Category::where('domain', $domain->name_slug)
            ->orderBy('name')
            ->get();

        return Inertia::render('Products/Create', [
            'categories' => $categories,
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => false,
            'currentLocation' => $location,
            'subscription' => $this->subscriptionService->subscriptionPropsForFrontend($domain),
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
        // All domain categories (same as category management), not only those with products at this store.
        $categories = Category::where('domain', $domain->name_slug)
            ->orderBy('name')
            ->get();

        return Inertia::render('Products/Edit', [
            'product' => [
                ...$product->toArray(),
                'representation_display_url' => ProductImageStorage::displayUrl(
                    $product->representation,
                    $product->representation_type,
                ),
            ],
            'categories' => $categories,
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => false,
            'currentLocation' => $location,
        ]);
    }
}
