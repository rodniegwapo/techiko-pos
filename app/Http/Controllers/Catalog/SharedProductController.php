<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product\ProductSoldType;
use App\Models\SharedProduct;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SharedProductController extends Controller
{
    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isSuperUser(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeSuper($request);

        $products = SharedProduct::query()
            ->when($request->search, function ($q, $s) {
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', '%'.$s.'%')
                        ->orWhere('barcode', 'like', '%'.$s.'%');
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Catalog/SharedProducts/Index', [
            'products' => $products,
            'sold_by_types' => ProductSoldType::all(),
            'filters' => [
                'search' => $request->search,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['required', 'string', 'max:255', 'unique:shared_products,barcode'],
            'description' => ['nullable', 'string'],
            'category_label' => ['nullable', 'string', 'max:255'],
            'sold_type' => ['nullable', 'string', 'max:255', 'exists:product_sold_types,name'],
            'representation_type' => ['nullable', 'string', 'in:image,color,text'],
            'representation' => ['nullable', 'string'],
        ]);

        SharedProduct::create($data);

        return redirect()->route('catalog.shared-products.index')->with('success', 'Shared product saved.');
    }

    public function update(Request $request, SharedProduct $shared_product)
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['required', 'string', 'max:255', 'unique:shared_products,barcode,'.$shared_product->id],
            'description' => ['nullable', 'string'],
            'category_label' => ['nullable', 'string', 'max:255'],
            'sold_type' => ['nullable', 'string', 'max:255', 'exists:product_sold_types,name'],
            'representation_type' => ['nullable', 'string', 'in:image,color,text'],
            'representation' => ['nullable', 'string'],
        ]);

        $shared_product->update($data);

        return redirect()->route('catalog.shared-products.index')->with('success', 'Shared product updated.');
    }

    public function destroy(Request $request, SharedProduct $shared_product)
    {
        $this->authorizeSuper($request);

        $shared_product->delete();

        return redirect()->route('catalog.shared-products.index')->with('success', 'Shared product deleted.');
    }
}
