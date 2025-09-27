<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product\Discount;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SaleController extends Controller
{
    protected $saleService;

    public function __construct(SaleService $saleService)
    {
        $this->saleService = $saleService;
    }

    public function index()
    {
        return Inertia::render('Sales/Index', [
            'categories' => Category::all(),
            'discounts' => Discount::all(),
            'mandatoryDiscounts' => \App\Models\MandatoryDiscount::where('is_active', true)->get(),
        ]);
    }

    public function products(Request $request)
    {
        $query = Product::query()
            ->when($request->input('search'), fn ($q, $search) => $q->search($search))
            ->when($request->input('category'), function ($q, $category) {
                $q->whereHas('category', fn ($q) => $q->where('name', $category));
            })
            ->with('category');

        $products = $query->when(
            ! $request->input('search') && ! $request->input('category'),
            fn ($q) => $q->limit(30)
        )->get();

        return ProductResource::collection($products);
    }

    public function proceedPayment(Request $request, Sale $sale)
    {
        $sale->update([
            'payment_status' => 'paid',
            'transaction_date' => now(),
            'grand_total' => $sale->total_amount,
        ]);

        return response()->noContent(200);
    }

    public function storeDraft(Request $request)
    {
        $order = $this->saleService->storeDraft($request->user());
        
        return response()->json(['order' => $order]);
    }

    public function syncDraft(Request $request, Sale $sale)
    {
        $this->saleService->syncDraft($sale, $request->items);

        return response()->json(['message' => 'Sale draft is syncing...']);
    }

    public function syncDraftImmediate(Request $request, Sale $sale)
    {
        $this->saleService->syncDraftImmediate($sale, $request->items);

        return response()->json(['message' => 'Sale draft synced successfully']);
    }

    public function voidItem(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'pin_code' => 'required|string',
            'reason' => 'nullable|string',
            'product_id' => 'required|integer',
        ]);

        $saleItem = $this->saleService->voidItem($sale, $validated, auth()->user());

        return response()->json([
            'message' => 'Sale item voided successfully.',
            'item' => $saleItem,
        ]);
    }

    public function findSaleItem(Request $request, Sale $sale)
    {
        return $sale->saleItems()
            ->where('product_id', $request->product_id)
            ->first();
    }

    public function processLoyalty(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'sale_amount' => 'required|numeric|min:0'
        ]);
        
        $customer = Customer::findOrFail($validated['customer_id']);
        
        // Update sale with customer
        $sale->update(['customer_id' => $customer->id]);
        
        // Process loyalty rewards
        $results = $customer->processLoyaltyForSale($validated['sale_amount']);
        
        return response()->json([
            'success' => true,
            'results' => $results,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'loyalty_points' => $customer->loyalty_points,
                'tier' => $customer->tier,
                'lifetime_spent' => $customer->lifetime_spent,
                'total_purchases' => $customer->total_purchases,
            ]
        ]);
    }
}
