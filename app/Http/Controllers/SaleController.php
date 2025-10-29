<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
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
        // Middleware is handled at route level
        $this->saleService = $saleService;
        $this->inventoryService = $inventoryService;
    }

    public function index(Request $request)
    {
        // Sales are now domain-only - redirect to domain selection or show error
        abort(403, 'Sales are only available within a domain context. Please select a domain first.');
    }

    public function products(Request $request)
    {
        $domain = $request->route('domain');
        $isDomainRoute = $request->route()->named('domains.*');

        $query = Product::query()
            ->when($request->input('search'), fn($q, $search) => $q->search($search))
            ->when($request->input('category'), function ($q, $category) {
                $q->whereHas('category', fn($q) => $q->where('name', $category));
            })
            ->with('category');

        // Filter by domain if this is a domain-specific route
        if ($isDomainRoute && $domain) {
            $query->where('domain', $domain);
        } elseif ($isDomainRoute) {
            // If domain route but no domain, return empty
            return ProductResource::collection(collect());
        }

        $products = $query->when(
            ! $request->input('search') && ! $request->input('category'),
            fn($q) => $q->limit(30)
        )->get();

        return ProductResource::collection($products);
    }

    public function proceedPayment(Request $request, Domain $domain, Sale $sale)
    {

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'sale_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string'
        ]);

        $loyaltyResults = null;

        try {
            // Note: Inventory validation removed to allow overselling
            // Overselling will be tracked and reconciled automatically via StockAdjustment

            // Use database transaction to ensure data consistency
            DB::transaction(function () use ($sale, $validated, &$loyaltyResults) {
                // 1. Complete sale and process inventory
                $this->saleService->completeSale($sale, auth()->user());

                // 2. Handle overselling situations (create automatic stock adjustments)
                $oversoldItems = $this->saleService->handleOverselling($sale);
                if (!empty($oversoldItems)) {
                    \Log::info('Oversell detected during sale completion', [
                        'sale_id' => $sale->id,
                        'invoice_number' => $sale->invoice_number,
                        'oversold_count' => count($oversoldItems)
                    ]);
                }

                // 3. Update payment details
                $sale->update([
                    'grand_total' => $sale->total_amount,
                    'payment_method' => $validated['payment_method']
                ]);

                // 4. Process loyalty if customer is provided
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

        // Trigger payment completed event to clear the order view
        event(new \App\Events\PaymentCompleted($sale));

        // Return response with loyalty results
        return response()->json([
            'success' => true,
            'message' => 'Payment processed successfully',
            'loyalty_results' => $loyaltyResults
        ]);
    }

    public function storeDraft(Request $request)
    {
        $domain = $request->route('domain');
        $user = $request->user();
        $userRole = $user->roles()->first();
        
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = \App\Helpers::getActiveLocation($domain);
            $locationId = $location?->id;
        } else {
            // Regular users: Use their assigned location
            $locationId = $user->location_id;
        }
        
        $order = $this->saleService->storeDraft($user, $locationId);

        return response()->json(['order' => $order]);
    }

    /**
     * Add item to cart
     */
    public function addItemToCart(Request $request, Sale $sale)
    {
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0'
        ]);

        // Note: Inventory validation removed to allow overselling
        // Overselling will be tracked and reconciled automatically via StockAdjustment

        // Find existing item or create new one
        $saleItem = $sale->saleItems()->where('product_id', $validated['product_id'])->first();

        if ($saleItem) {
            // Item exists - increment quantity
            $saleItem->increment('quantity', $validated['quantity']);
        } else {
            // Item doesn't exist - create new one
            $saleItem = $sale->saleItems()->create([
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'unit_price' => $validated['unit_price']
            ]);
        }

        $sale->recalcTotals();

        // Load the product relationship for the saleItem
        $saleItem->load('product');

        // Refresh the sale with all relationships
        $sale->load(['saleItems.product', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'item' => $saleItem,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Remove item from cart
     */
    public function removeItemFromCart(Request $request, Sale $sale)
    {
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $sale->saleItems()->where('product_id', $validated['product_id'])->delete();
        $sale->recalcTotals();

        // Refresh the sale with all relationships
        $sale->load(['saleItems.product', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Update item quantity in cart
     */
    public function updateItemQuantity(Request $request, Sale $sale)
    {
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1'
        ]);

        $saleItem = $sale->saleItems()
            ->where('product_id', $validated['product_id'])
            ->firstOrFail();

        $saleItem->update(['quantity' => $validated['quantity']]);
        $sale->recalcTotals();

        // Load the product relationship for the saleItem
        $saleItem->load('product');

        // Refresh the sale with all relationships
        $sale->load(['saleItems.product', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'item' => $saleItem,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Get current cart state
     */
    public function getCartState(Request $request, Sale $sale)
    {
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

        $sale->load(['saleItems.product', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Get current active discounts from database
     */
    public function getCurrentDiscounts(Request $request)
    {
        $domain = $request->route('domain');

        // Get active regular discounts
        $regularDiscounts = Discount::where('is_active', true)
            ->where('scope', 'order')
            ->where('start_date', '<=', now())
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->when($domain, function ($query, $domain) {
                $query->where('domain', $domain);
            })
            ->get();

        // Get active mandatory discounts
        $mandatoryDiscounts = \App\Models\MandatoryDiscount::where('is_active', true)
            ->when($domain, function ($query, $domain) {
                $query->where('domain', $domain);
            })
            ->get();

        return response()->json([
            'success' => true,
            'regular_discounts' => $regularDiscounts,
            'mandatory_discounts' => $mandatoryDiscounts
        ]);
    }

    /**
     * Get sale-specific discount state
     */
    public function getSaleDiscounts(Request $request, Domain $domain, Sale $sale)
    {
        $sale->load(['saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        $regularDiscounts = $sale->saleDiscounts()
            ->where('discount_type', 'regular')
            ->with('discount')
            ->get()
            ->pluck('discount');

        $mandatoryDiscounts = $sale->saleDiscounts()
            ->where('discount_type', 'mandatory')
            ->with('mandatoryDiscount')
            ->get()
            ->pluck('mandatoryDiscount');

        return response()->json([
            'success' => true,
            'regular_discounts' => $regularDiscounts,
            'mandatory_discounts' => $mandatoryDiscounts,
            'total_discount_amount' => $sale->discount_amount
        ]);
    }

    /**
     * Update sale discounts
     */
    public function updateSaleDiscounts(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'regular_discount_ids' => 'array',
            'regular_discount_ids.*' => 'exists:discounts,id',
            'mandatory_discount_ids' => 'array',
            'mandatory_discount_ids.*' => 'exists:mandatory_discounts,id'
        ]);

        try {
            $saleDiscountService = app(\App\Services\SaleDiscountService::class);
            $result = $saleDiscountService->applyOrderDiscounts(
                $sale,
                $validated['regular_discount_ids'] ?? [],
                $validated['mandatory_discount_ids'] ?? []
            );

            return response()->json([
                'success' => true,
                'sale' => $result['sale'],
                'discounts' => $result['sale_discounts']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Remove all discounts from a sale
     */
    public function removeSaleDiscounts(Request $request, Sale $sale)
    {
        try {
            $saleDiscountService = app(\App\Services\SaleDiscountService::class);
            $sale = $saleDiscountService->removeOrderDiscounts($sale);

            return response()->json([
                'success' => true,
                'message' => 'All discounts removed successfully',
                'sale' => $sale->load(['saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function voidItem(Request $request, Sale $sale)
    {
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

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
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

        return $sale->saleItems()
            ->with('discounts') // Load the discounts relationship
            ->where('product_id', $request->product_id)
            ->first();
    }

    public function assignCustomer(Request $request, Sale $sale)
    {
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

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
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

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
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

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

    public function testOrderEvent(Request $request, Sale $sale)
    {
        // Ensure the sale belongs to the current domain if in domain context
        $domain = $request->route('domain');
        if ($domain && $sale->domain !== $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found in this domain'
            ], 404);
        }

        \Log::info("Testing OrderUpdated event", [
            'sale_id' => $sale->id
        ]);

        // Manually trigger the OrderUpdated event
        event(new \App\Events\OrderUpdated($sale->fresh([
            'saleItems.product',
            'saleDiscounts',
            'saleItems.discounts',
            'customer'
        ])));

        return response()->json([
            'success' => true,
            'message' => 'OrderUpdated event triggered for testing',
            'sale_id' => $sale->id
        ]);
    }

    /**
     * Get current user's latest pending sale in the current domain
     */
    public function getCurrentPendingSale(Request $request)
    {
        $domain = $request->route('domain');
        $userId = auth()->id();
        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;

        $query = Sale::forDomain($domain)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->with(['saleItems.product', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount', 'saleItems.discounts']);

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
        } else {
            // Regular users: Filter by their assigned location
            $query->where('location_id', $currentUser->location_id);
        }

        $sale = $query->first();

        // Always return success, even if no sale found
        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale ? $sale->saleItems : [],
            'discounts' => $sale ? $sale->saleDiscounts : [],
            'totals' => $sale ? [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ] : [
                'subtotal' => 0,
                'discount_amount' => 0,
                'grand_total' => 0
            ]
        ]);
    }

    /**
     * Create a new sale for a specific user
     */
    public function createSaleForUser(Request $request, $userId)
    {
        $domain = $request->route('domain');
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');
        // Verify the user exists and is accessible
        $user = \App\Models\User::findOrFail($userId);

        // Determine location based on current user's role
        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;
        
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = \App\Helpers::getActiveLocation($domain);
            $locationId = $location?->id;
        } else {
            // Regular users: Use their assigned location
            $locationId = $user->location_id;
        }

        // Create new sale for this user with location
        $sale = $this->saleService->storeDraft($user, $locationId);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'message' => 'Sale created for user'
        ]);
    }

    /**
     * Add item to user's latest pending sale (auto-finds or creates)
     */
    public function addItemToUserCart(Request $request, $userId)
    {
        $domain = $request->route('domain');

        // Explicitly get the 'user' parameter from the route to avoid parameter binding issues
        $userId = $request->route('user');

        // Debug: Log the received parameters
        \Log::info('addItemToUserCart called', [
            'original_userId' => $userId,
            'route_user_param' => $request->route('user'),
            'domain' => $domain,
            'route_parameters' => $request->route()->parameters(),
            'all_parameters' => $request->all()
        ]);

        $user = \App\Models\User::findOrFail($userId);

        // Get or create latest pending sale for this user
        $sale = Sale::forDomain($domain)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        // If no pending sale exists, create one with location
        if (!$sale) {
            $currentUser = auth()->user();
            $userRole = $currentUser ? $currentUser->roles()->first() : null;
            
            if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
                // Admin/Super Admin: Use Helpers::getActiveLocation()
                $location = \App\Helpers::getActiveLocation($domain);
                $locationId = $location?->id;
            } else {
                // Regular users: Use their assigned location
                $locationId = $user->location_id;
            }
            
            $sale = $this->saleService->storeDraft($user, $locationId);
        }

        // Now add item to the sale
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0'
        ]);

        // Add item to cart logic here...
        $saleItem = $sale->saleItems()->where('product_id', $validated['product_id'])->first();

        if ($saleItem) {
            $saleItem->increment('quantity', $validated['quantity']);
        } else {
            $saleItem = $sale->saleItems()->create([
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'unit_price' => $validated['unit_price']
            ]);
        }

        $sale->recalcTotals();
        $sale->load(['saleItems.product', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Get user's current pending sale
     */
    public function getUserPendingSale(Request $request, $userId)
    {
        $domain = $request->route('domain');
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');
        
        $currentUser = auth()->user();
        $userRole = $currentUser->roles()->first();
        
        $query = Sale::forDomain($domain)
            ->pending()
            ->orderBy('created_at', 'desc')
            ->with(['saleItems.product', 'saleItems.discounts', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);
        
        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
        } else {
            // Regular users: Filter by their assigned location and user ID
            $query->where('location_id', $currentUser->location_id)
                  ->where('user_id', $userId);
        }
        
        $sale = $query->first();

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale ? $sale->saleItems : [],
            'discounts' => $sale ? $sale->saleDiscounts : [],
            'totals' => $sale ? [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ] : [
                'subtotal' => 0,
                'discount_amount' => 0,
                'grand_total' => 0
            ]
        ]);
    }

    /**
     * Update user cart quantity
     */
    public function updateUserCartQuantity(Request $request, $userId)
    {
        $domain = $request->route('domain');
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');
        
        $currentUser = auth()->user();
        $userRole = $currentUser->roles()->first();
        
        $query = Sale::forDomain($domain)
            ->pending()
            ->orderBy('created_at', 'desc');
        
        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
        } else {
            // Regular users: Filter by their assigned location and user ID
            $query->where('location_id', $currentUser->location_id)
                  ->where('user_id', $userId);
        }
        
        // Get user's latest pending sale
        $sale = $query->first();

        if (!$sale) {
            return response()->json(['success' => false, 'message' => 'No pending sale found'], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1'
        ]);

        $saleItem = $sale->saleItems()
            ->where('product_id', $validated['product_id'])
            ->firstOrFail();

        $saleItem->update(['quantity' => $validated['quantity']]);
        $sale->recalcTotals();

        $sale->load(['saleItems.product', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Remove item from user cart
     */
    public function removeFromUserCart(Request $request, $userId)
    {
        $domain = $request->route('domain');
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');

        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;

        $query = Sale::forDomain($domain)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
        } else {
            // Regular users: Filter by their assigned location
            $query->where('location_id', $currentUser->location_id);
        }

        // Get user's latest pending sale
        $sale = $query->first();

        if (!$sale) {
            return response()->json(['success' => false, 'message' => 'No pending sale found'], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $sale->saleItems()->where('product_id', $validated['product_id'])->delete();
        $sale->recalcTotals();

        $sale->load(['saleItems.product', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Get user cart state
     */
    public function getUserCartState(Request $request, $userId)
    {
        $domain = $request->route('domain');
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');

        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;

        $query = Sale::forDomain($domain)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->with(['saleItems.product', 'saleItems.discounts', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
        } else {
            // Regular users: Filter by their assigned location
            $query->where('location_id', $currentUser->location_id);
        }

        // Get user's latest pending sale
        $sale = $query->first();

        if (!$sale) {
            return response()->json([
                'success' => true,
                'sale' => null,
                'items' => [],
                'discounts' => [],
                'totals' => [
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'grand_total' => 0
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total
            ]
        ]);
    }

    /**
     * Get oversell statistics for management reporting
     */
    public function getOversellStatistics(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'domain' => 'nullable|string'
        ]);

        $statistics = $this->saleService->getOversellStatistics(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $validated['domain'] ?? null
        );

        return response()->json([
            'success' => true,
            'statistics' => $statistics
        ]);
    }
}
