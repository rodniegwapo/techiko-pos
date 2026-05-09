<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Domain;
use App\Models\Product\Product;
use App\Models\Product\ProductSoldType;
use App\Services\DomainSubscriptionService;
use App\Support\BarcodeNormalizer;
use App\Support\ProductPayloadNormalizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(
        private DomainSubscriptionService $subscriptionService,
    ) {
        // Middleware is handled at route level
    }

    public function index(Request $request)
    {
        $product = Product::query()
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('sold_type'), function ($query, $sold_type) {
                return $query->where('sold_type', $sold_type);
            })
            ->when($request->input('category'), function ($query, $category) {
                return $query->whereHas('category', function ($query) use ($category) {
                    return $query->where('name', $category);
                });
            })
            ->when($request->input('price'), function ($query, $price) {
                return $query->where('price', $price);
            })
            ->when($request->input('cost'), function ($query, $cost) {
                return $query->where('cost', $cost);
            })
            ->with(['category', 'locations'])->paginate($request?->data['per_page'] ?? 10);

        return inertia('Products/Index', [
            'items' => ProductResource::collection($product),
            'categories' => Category::all(),
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => true,
            'domains' => Domain::select('id', 'name', 'name_slug')->get(),
        ]);
    }

    public function create(Request $request)
    {
        return inertia('Products/Create', [
            'categories' => Category::query()->orderBy('domain')->orderBy('name')->get(),
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => true,
            'currentLocation' => null,
            'domains' => Domain::select('id', 'name', 'name_slug')->get(),
        ]);
    }

    public function edit(Request $request, Product $product)
    {
        return inertia('Products/Edit', [
            'product' => $product,
            'categories' => Category::query()
                ->when($product->domain, fn ($q) => $q->where('domain', $product->domain))
                ->orderBy('name')
                ->get(),
            'sold_by_types' => ProductSoldType::all(),
            'isGlobalView' => true,
            'currentLocation' => null,
            'domains' => Domain::select('id', 'name', 'name_slug')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = ProductPayloadNormalizer::applyRepresentationAndCostDefaults($this->validatedData($request, null));

        $domainModel = isset($data['domain'])
            ? Domain::where('name_slug', $data['domain'])->first()
            : null;

        if ($domainModel) {
            $this->subscriptionService->assertCanCreateProduct($domainModel);
        }

        Product::create($data);

        return redirect()->back();
    }

    public function update(Request $request, Product $product)
    {
        $data = ProductPayloadNormalizer::applyRepresentationAndCostDefaults($this->validatedData($request, $product));

        $product->update($data);

        return redirect()->back();
    }

    public function destroy(Request $request, Product $product)
    {
        $product->delete();

        return redirect()->back();
    }

    private function validatedData(Request $request, ?Product $product = null): array
    {
        $productId = $product?->id;

        $barcodeRules = ['required', 'string', 'max:255'];
        if ($request->filled('domain')) {
            $domainSlug = $request->domain;
            $barcodeRules[] = Rule::unique('products', 'barcode')
                ->where(fn ($q) => $q->where('domain', $domainSlug))
                ->ignore($productId);
        } else {
            $barcodeRules[] = Rule::unique('products', 'barcode')->ignore($productId);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'sold_type' => ['required', 'exists:product_sold_types,name'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'SKU' => ['required', 'string', 'max:100', 'unique:products,SKU,'.$productId],
            'barcode' => $barcodeRules,
            'representation_type' => ['nullable', 'string', 'in:image,color,text'],
            'representation' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ];

        if ($request->has('domain') && $request->domain) {
            $rules['domain'] = ['required', 'string', 'exists:domains,name_slug'];
        }

        $data = $request->validate($rules);

        if (array_key_exists('category_id', $data) && $data['category_id'] === '') {
            $data['category_id'] = null;
        }
        if (! empty($data['barcode'])) {
            $data['barcode'] = BarcodeNormalizer::normalize($data['barcode']);
        }

        return $data;
    }
}
