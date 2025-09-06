<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSoldType;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $product = Product::query()->when($request->input('search'), function ($query, $search) {
            return $query->search($search);
        })->with('category')->paginate($request?->data['per_page'] ?? 10);

        return inertia('Products/Index', [
            'items' => ProductResource::collection($product),
            'categories' => Category::all(),
            'sold_by_types' => ProductSoldType::all()
        ]);
    }


    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        Product::create($data);

        return redirect()->back();
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validatedData($request);

        $product->update($data);

        return redirect()->back();
    }

    public function destroy(Request $request, Product $product)
    {
        $product->delete();

        redirect()->back();
    }

    private function validatedData(Request $request)
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sold_type' => ['required', 'exists:product_sold_types,name'], // must exist in table
            'price' => ['required', 'integer', 'min:0'],
            'cost' => ['required', 'integer', 'min:0'],
            'SKU' => ['required', 'string', 'max:100', 'unique:products,SKU,' . $request->id],
            'barcode' => ['required', 'string', 'max:255', 'unique:products,barcode,' . $request->id],
            'representation_type' => ['nullable', 'string', 'in:image,color,text'],
            'representation' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);
    }
}
