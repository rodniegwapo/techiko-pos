<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Str;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Sales/Index', [
            'categories' => Category::all()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Sale $sale)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sale $sale)
    {
        //
    }

    public function products(Request $request)
    {
        $product = Product::query()
            ->when($request->input('search'), function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->input('category'), function ($query, $category) {
                return $query->whereHas('category', function ($query) use ($category) {
                    return $query->where('name', $category);
                });
            })->with('category')->get();


        return ProductResource::collection($product);
    }

    public function storeDraft(Request $request)
    {
        $order = Sale::create([
            'user_id' => $request->user()->id,
            'payment_status' => 'pending',
            'invoice_number' => Str::random(10),
            'transaction_date' => now()
        ]);

        return response()->json(['order' => $order]);
    }

    public function syncDraft(Request $request, Sale $sale)
    {
        DB::transaction(function () use ($request, $sale) {
            $sale->items()->delete(); // overwrite draft

            foreach ($request->items as $item) {
                $sale->items()->create([
                    'product_id' => $item['id'],
                    'quantity'   => $item['quantity'],
                    'unit_price'      => $item['price'],
                    'subtotal' => $item['price'] * $item['quantity']
                ]);
            }

            $sale->total_amount = collect($request->items)
                ->sum(fn($i) => $i['quantity'] * $i['price']);
            $sale->save();
        });

        return response()->json(['order' => $sale->fresh('items')]);
    }
}
