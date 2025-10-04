<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product\Discount;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\InventoryLocation;
use App\Services\SaleService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SaleController extends Controller
{
    protected $saleService;
    protected $inventoryService;

    public function __construct(SaleService $saleService, InventoryService $inventoryService)
    {
        $this->saleService = $saleService;
        $this->inventoryService = $inventoryService;
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
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'sale_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string'
        ]);
        
        $loyaltyResults = null;
        
        try {
            // Check inventory availability before processing payment
            $unavailableItems = $this->saleService->validateStockAvailability($sale);
            
            if (!empty($unavailableItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some items are not available in sufficient quantities',
                    'unavailable_items' => $unavailableItems
                ], 400);
            }

            // Use database transaction to ensure data consistency
            DB::transaction(function () use ($sale, $validated, &$loyaltyResults) {
                // 1. Complete sale and process inventory
                $this->saleService->completeSale($sale, auth()->user());
                
                // 2. Update payment details
                $sale->update([
                    'grand_total' => $sale->total_amount,
                    'payment_method' => $validated['payment_method']
                ]);
                
                // 3. Process loyalty if customer is provided
                if ($validated['customer_id']) {
                    $customer = Customer::findOrFail($validated['customer_id']);
                    
                    // Link customer to sale and trigger order update event
                    $sale->updateCustomer($customer->id);
                    
                    // Process loyalty rewards
                    $loyaltyResults = $customer->processLoyaltyForSale($validated['sale_amount'] ?? $sale->total_amount);
                    
                    \Log::info('Loyalty processed for sale', [
                        'sale_id' => $sale->id,
                        'customer_id' => $customer->id,
                        'points_earned' => $loyaltyResults['points_earned'] ?? 0,
                        'tier_upgraded' => $loyaltyResults['tier_upgraded'] ?? false
                    ]);
                }
            });
        } catch (\Exception $e) {
            \Log::error('Payment processing failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'customer_id' => $validated['customer_id'] ?? null
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ], 500);
        }
        
        // Return response with loyalty results
        return response()->json([
            'success' => true,
            'message' => 'Payment processed successfully',
            'loyalty_results' => $loyaltyResults
        ]);
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
            ->with('discounts') // Load the discounts relationship
            ->where('product_id', $request->product_id)
            ->first();
    }

    public function assignCustomer(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id'
        ]);
        
        \Log::info("SaleController::assignCustomer called", [
            'sale_id' => $sale->id,
            'customer_id' => $validated['customer_id']
        ]);
        
        // Update sale with customer and trigger customer update event
        $sale->updateCustomer($validated['customer_id']);
        
        $response = [
            'success' => true,
            'message' => $validated['customer_id'] ? 'Customer assigned successfully' : 'Customer removed successfully'
        ];
        
        // If customer is assigned, include customer data in response
        if ($validated['customer_id']) {
            $customer = \App\Models\Customer::findOrFail($validated['customer_id']);
            $response['customer'] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'loyalty_points' => $customer->loyalty_points,
                'tier_info' => $customer->tier_info,
                'loyalty_id' => $customer->loyalty_id,
                'membership_number' => $customer->membership_number,
            ];
        }
        
        return response()->json($response);
    }

    public function testCustomerEvent(Request $request, Sale $sale)
    {
        \Log::info("Testing CustomerUpdated event", [
            'sale_id' => $sale->id
        ]);
        
        // Manually trigger the CustomerUpdated event
        event(new \App\Events\CustomerUpdated($sale));
        
        return response()->json([
            'success' => true,
            'message' => 'CustomerUpdated event triggered for testing'
        ]);
    }

    public function processLoyalty(Request $request, Sale $sale)
    {
        // Ensure sale is paid (optional validation)
        if ($sale->payment_status !== 'paid') {
            return response()->json(['error' => 'Sale not completed'], 400);
        }
        
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'sale_amount' => 'required|numeric|min:0'
        ]);
        
        $customer = Customer::findOrFail($validated['customer_id']);
        
        // Update sale with customer and trigger order update event
        $sale->updateCustomer($customer->id);
        
        // Process loyalty rewards
        $results = $customer->processLoyaltyForSale($validated['sale_amount']);
        
        // Return results in the format expected by frontend
        return response()->json(array_merge([
            'success' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'loyalty_points' => $customer->loyalty_points,
                'tier' => $customer->tier,
                'lifetime_spent' => $customer->lifetime_spent,
                'total_purchases' => $customer->total_purchases,
            ]
        ], $results));
    }
}
