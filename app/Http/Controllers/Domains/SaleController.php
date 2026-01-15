<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Product\Discount;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Services\CreditService;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Traits\LocationCategoryScoping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SaleController extends Controller
{
    use LocationCategoryScoping;

    protected $saleService;

    protected $inventoryService;

    protected $creditService;

    public function __construct(SaleService $saleService, InventoryService $inventoryService, CreditService $creditService)
    {
        $this->saleService = $saleService;
        $this->inventoryService = $inventoryService;
        $this->creditService = $creditService;
    }

    public function index(Request $request, Domain $domain)
    {
        $location = Helpers::getActiveLocation($domain);

        return Inertia::render('Sales/Index', [
            'domain' => $domain,
            'categories' => $location
                ? $this->getCategoriesForLocation($domain->name_slug, $location)->get()
                : Category::where('domain', $domain->name_slug)->get(),
        ]);
    }

    public function products(Request $request, Domain $domain)
    {
        $location = Helpers::getActiveLocation($domain, $request->input('location_id'));

        $query = Product::query()
            ->where('domain', $domain->name_slug)
            ->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('location_id', $location->id);
            })
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

    public function proceedPayment(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'sale_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|in:cash,card,e-wallet,credit',
        ]);

        $loyaltyResults = null;
        $creditResults = null;

        $location = Helpers::getActiveLocation($domain);

        try {
            DB::transaction(function () use ($sale, $validated, &$loyaltyResults, &$creditResults, $location) {
                // 1. Complete sale and process inventory
                $this->saleService->completeSale($sale, auth()->user());

                // 2. Handle overselling situations (create automatic stock adjustments)
                $oversoldItems = $this->saleService->handleOverselling($sale);

                // 3. Handle credit payment if selected
                if ($validated['payment_method'] === 'credit') {
                    if (!$validated['customer_id']) {
                        throw new \Exception('Customer is required for credit payments.');
                    }

                    $customer = Customer::findOrFail($validated['customer_id']);

                    // Validate credit limit
                    $saleAmount = $validated['sale_amount'] ?? $sale->grand_total;
                    $this->creditService->checkCreditLimit($customer, $saleAmount);

                    // Process credit sale
                    $creditTransaction = $this->creditService->processCreditSale(
                        $sale,
                        $customer,
                        $saleAmount
                    );

                    $creditResults = [
                        'transaction_id' => $creditTransaction->id,
                        'credit_balance' => $customer->fresh()->credit_balance,
                        'available_credit' => $customer->fresh()->getAvailableCredit(),
                    ];

                    // Link customer to sale
                    $sale->updateCustomer($customer->id);
                } else {
                    // 4. Update payment details for non-credit payments
                    $sale->update([
                        'grand_total' => $sale->total_amount,
                        'payment_method' => $validated['payment_method'],
                        'location_id' => $location->id,
                        'payment_status' => 'paid',
                    ]);

                    // 5. Process loyalty if customer is provided
                    if ($validated['customer_id']) {
                        $customer = Customer::findOrFail($validated['customer_id']);

                        // Link customer to sale and trigger order update event
                        $sale->updateCustomer($customer->id);

                        // Process loyalty rewards
                        $loyaltyResults = $customer->processLoyaltyForSale($validated['sale_amount'] ?? $sale->total_amount);
                    }
                }
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed: '.$e->getMessage(),
            ], 500);
        }

        // Trigger payment completed event to clear the order view
        event(new \App\Events\PaymentCompleted($sale));

        // Return response with loyalty and credit results
        return response()->json([
            'success' => true,
            'message' => 'Payment processed successfully',
            'loyalty_results' => $loyaltyResults,
            'credit_results' => $creditResults,
        ]);
    }

    public function storeDraft(Request $request, Domain $domain)
    {
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
    public function addItemToCart(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        // Fetch product price from database for security and data integrity
        $product = Product::findOrFail($validated['product_id']);
        $unitPrice = $product->price;

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
                'unit_price' => $unitPrice,
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
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Remove item from cart
     */
    public function removeItemFromCart(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
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
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Update item quantity in cart
     */
    public function updateItemQuantity(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
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
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Get current cart state
     */
    public function getCartState(Request $request, Domain $domain, Sale $sale)
    {
        $sale->load(['saleItems.product', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Get current active discounts from database
     */
    public function getCurrentDiscounts(Request $request, Domain $domain)
    {
        // Get active regular discounts (order-level)
        // Handle NULL start_date - if start_date is NULL, treat as "always active"
        $regularDiscounts = Discount::where('is_active', true)
            ->where('scope', 'order')
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where('domain', $domain->name_slug)
            ->get();

        // Get active product discounts (product-level)
        $productDiscounts = Discount::where('is_active', true)
            ->where('scope', 'product')
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where('domain', $domain->name_slug)
            ->get();

        // Get active mandatory discounts
        $mandatoryDiscounts = \App\Models\MandatoryDiscount::where('is_active', true)
            ->where('domain', $domain->name_slug)
            ->get();

        return response()->json([
            'success' => true,
            'regular_discounts' => $regularDiscounts,
            'product_discounts' => $productDiscounts,
            'mandatory_discounts' => $mandatoryDiscounts,
        ]);
    }

    public function voidItem(Request $request, Domain $domain, Sale $sale)
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

    public function findSaleItem(Request $request, Domain $domain, Sale $sale)
    {
        return $sale->saleItems()
            ->with('discounts') // Load the discounts relationship
            ->where('product_id', $request->product_id)
            ->first();
    }

    public function assignCustomer(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        \Log::info("Domains\SaleController::assignCustomer called", [
            'sale_id' => $sale->id,
            'customer_id' => $validated['customer_id'],
        ]);

        // Update sale with customer and trigger customer update event
        $sale->updateCustomer($validated['customer_id']);

        $response = [
            'success' => true,
            'message' => $validated['customer_id'] ? 'Customer assigned successfully' : 'Customer removed successfully',
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

    public function testCustomerEvent(Request $request, Domain $domain, Sale $sale)
    {
        \Log::info('Testing CustomerUpdated event', [
            'sale_id' => $sale->id,
        ]);

        // Manually trigger the CustomerUpdated event
        event(new \App\Events\CustomerUpdated($sale));

        return response()->json([
            'success' => true,
            'message' => 'CustomerUpdated event triggered for testing',
        ]);
    }

    public function processLoyalty(Request $request, Domain $domain, Sale $sale)
    {
        // Ensure sale is paid (optional validation)
        if ($sale->payment_status !== 'paid') {
            return response()->json(['error' => 'Sale not completed'], 400);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'sale_amount' => 'required|numeric|min:0',
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
            ],
        ], $results));
    }

    public function testOrderEvent(Request $request, Domain $domain, Sale $sale)
    {
        \Log::info('Testing OrderUpdated event', [
            'sale_id' => $sale->id,
        ]);

        // Manually trigger the OrderUpdated event
        event(new \App\Events\OrderUpdated($sale->fresh([
            'saleItems.product',
            'saleDiscounts',
            'saleItems.discounts',
            'customer',
        ])));

        return response()->json([
            'success' => true,
            'message' => 'OrderUpdated event triggered for testing',
            'sale_id' => $sale->id,
        ]);
    }

    /**
     * Get current user's latest pending sale in the current domain
     */
    public function getCurrentPendingSale(Request $request, Domain $domain)
    {
        $userId = auth()->id();
        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;

        $query = Sale::forDomain($domain->name_slug)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->with(['saleItems.product', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount', 'saleItems.discounts']);

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
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
                'grand_total' => $sale->grand_total,
            ] : [
                'subtotal' => 0,
                'discount_amount' => 0,
                'grand_total' => 0,
            ],
        ]);
    }

    /**
     * Create a new sale for a specific user
     */
    public function createSaleForUser(Request $request, Domain $domain, $userId)
    {
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
            'message' => 'Sale created for user',
        ]);
    }

    /**
     * Add item to user's latest pending sale (auto-finds or creates)
     */
    public function addItemToUserCart(Request $request, Domain $domain, $userId)
    {
        // Explicitly get the 'user' parameter from the route to avoid parameter binding issues
        $userId = $request->route('user');

        // Debug: Log the received parameters
        \Log::info('addItemToUserCart called', [
            'original_userId' => $userId,
            'route_user_param' => $request->route('user'),
            'domain' => $domain->name_slug,
            'route_parameters' => $request->route()->parameters(),
            'all_parameters' => $request->all(),
        ]);

        $user = \App\Models\User::findOrFail($userId);

        $currentUser = auth()->user();
        $userRole = $currentUser->roles()->first();

        $query = Sale::forDomain($domain->name_slug)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
        } else {
            // Regular users: Filter by their assigned location
            $query->where('location_id', $currentUser->location_id);
        }

        // Get or create latest pending sale for this user
        $sale = $query->first();

        // If no pending sale exists, create one with location
        if (! $sale) {
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
        ]);

        // Fetch product price from database for security and data integrity
        $product = Product::findOrFail($validated['product_id']);
        $unitPrice = $product->price;

        // Add item to cart logic here...
        $saleItem = $sale->saleItems()->where('product_id', $validated['product_id'])->first();

        if ($saleItem) {
            $saleItem->increment('quantity', $validated['quantity']);
        } else {
            $saleItem = $sale->saleItems()->create([
                'product_id' => $validated['product_id'],
                'quantity' => $validated['quantity'],
                'unit_price' => $unitPrice,
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
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Get user's current pending sale
     */
    public function getUserPendingSale(Request $request, Domain $domain, $userId)
    {
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');

        $currentUser = auth()->user();
        $userRole = $currentUser->roles()->first();

        $query = Sale::forDomain($domain->name_slug)
            ->pending()
            ->orderBy('created_at', 'desc')
            ->with(['saleItems.product', 'saleItems.discounts', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
            // Always scope to target user
            $query->where('user_id', $userId);
        } else {
            // Regular users: Filter by their assigned location and user ID
            $query->where('location_id', $currentUser->location_id)
                ->where('user_id', $userId);
        }

        $sale = $query->first();

        // Get all available discount options
        // Handle NULL start_date - if start_date is NULL, treat as "always active"
        $productDiscounts = Discount::where('is_active', true)
            ->where('scope', 'product')
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where('domain', $domain->name_slug)
            ->get();

        $promotionalDiscounts = Discount::where('is_active', true)
            ->where('scope', 'order')
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->where('domain', $domain->name_slug)
            ->get();

        $mandatoryDiscounts = \App\Models\MandatoryDiscount::where('is_active', true)
            ->where('domain', $domain->name_slug)
            ->get();

        // Transform sale items to include discount information
        $transformedItems = [];
        if ($sale && $sale->saleItems) {
            foreach ($sale->saleItems as $item) {
                $itemData = $item->toArray();

                // Add discount information if item has discounts
                if ($item->discounts && $item->discounts->count() > 0) {
                    $discount = $item->discounts->first(); // Get the first discount
                    $itemData['discount_id'] = $discount->id;
                    $itemData['discount_type'] = $discount->type;
                    $itemData['discount_amount'] = $discount->value;
                }

                $transformedItems[] = $itemData;
            }
        }

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $transformedItems,
            'discounts' => $sale ? $sale->saleDiscounts : [],
            'totals' => $sale ? [
                'subtotal' => $sale->total_amount,
                'discount_amount' => $sale->discount_amount,
                'grand_total' => $sale->grand_total,
            ] : [
                'subtotal' => 0,
                'discount_amount' => 0,
                'grand_total' => 0,
            ],
            'discount_options' => [
                'product_discount_options' => $productDiscounts,
                'promotional_discount_options' => $promotionalDiscounts,
                'mandatory_discount_options' => $mandatoryDiscounts,
            ],
        ]);
    }

    /**
     * Update user cart quantity
     */
    public function updateUserCartQuantity(Request $request, Domain $domain, $userId)
    {
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');

        $currentUser = auth()->user();
        $userRole = $currentUser->roles()->first();

        $query = Sale::forDomain($domain->name_slug)
            ->pending()
            ->orderBy('created_at', 'desc');

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
            // Always scope to target user
            $query->where('user_id', $userId);
        } else {
            // Regular users: Filter by their assigned location and user ID
            $query->where('location_id', $currentUser->location_id)
                ->where('user_id', $userId);
        }

        // Get user's latest pending sale
        $sale = $query->first();

        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'No pending sale found'], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
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
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Remove item from user cart
     */
    public function removeFromUserCart(Request $request, Domain $domain, $userId)
    {
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');

        $currentUser = auth()->user();
        $userRole = $currentUser->roles()->first();

        $query = Sale::forDomain($domain->name_slug)
            ->pending()
            ->orderBy('created_at', 'desc');

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Filter by default location or current location
            $defaultLocation = $currentUser->location_id ?? $request->input('location_id');
            if ($defaultLocation) {
                $query->where('location_id', $defaultLocation);
            }
        } else {
            // Regular users: Filter by their assigned location and user ID
            $query->where('location_id', $currentUser->location_id)
                ->where('user_id', $userId);
        }

        // Get user's latest pending sale
        $sale = $query->first();

        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'No pending sale found'], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
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
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Get user cart state
     */
    public function getUserCartState(Request $request, Domain $domain, $userId)
    {
        // Explicitly get the 'user' parameter from the route
        $userId = $request->route('user');
        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;

        $query = Sale::forDomain($domain->name_slug)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->with(['saleItems.product', 'saleItems.discounts', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = \App\Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
        } else {
            // Regular users: Filter by their assigned location
            $query->where('location_id', $currentUser->location_id);
        }

        $sale = $query->first();

        if (! $sale) {
            return response()->json([
                'success' => true,
                'sale' => null,
                'items' => [],
                'discounts' => [],
                'totals' => [
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'grand_total' => 0,
                ],
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
                'grand_total' => $sale->grand_total,
            ],
        ]);
    }

    /**
     * Get oversell statistics for management reporting
     */
    public function getOversellStatistics(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $statistics = $this->saleService->getOversellStatistics(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $domain->name_slug
        );

        return response()->json([
            'success' => true,
            'statistics' => $statistics,
        ]);
    }
}
