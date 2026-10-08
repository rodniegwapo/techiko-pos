<?php

namespace App\Http\Controllers\Domains;

use App\Events\CustomerUpdated;
use App\Events\OrderUpdated;
use App\Events\PaymentCompleted;
use App\Exceptions\InsufficientStockException;
use App\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSellableLocation;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\MandatoryDiscount;
use App\Models\OfflineSaleSync;
use App\Models\PaymentCardType;
use App\Models\Product\Discount;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\CreditService;
use App\Services\InventoryService;
use App\Services\LoyaltyRedemptionService;
use App\Services\ProductModifierService;
use App\Services\SaleService;
use App\Traits\LocationCategoryScoping;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SaleController extends Controller
{
    use LocationCategoryScoping;

    /** The options picked for a product being added (Size, Add-ons…) and a note for the line. */
    private const LINE_OPTION_RULES = [
        'modifier_ids' => 'nullable|array|max:30',
        'modifier_ids.*' => 'integer',
        'notes' => 'nullable|string|max:200',
    ];

    /** Which cart line a change is for: sale_item_id names it; without one, the product's first line. */
    private const LINE_TARGET_RULES = [
        'sale_item_id' => 'nullable|integer',
    ];

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
        $vat = $domain->salesVatSettings();

        return Inertia::render('Sales/Index', [
            'domain' => $domain,
            'categories' => $location
                ? $this->getCategoriesForLocation($domain->name_slug, $location)->get()
                : Category::where('domain', $domain->name_slug)->get(),
            'salesSettings' => [
                'apply_vat_automatically' => $vat['apply_vat_automatically'],
                'vat_rate_percent' => $vat['vat_rate_percent'],
                'vat_pricing_mode' => $vat['vat_pricing_mode'],
                'hide_out_of_stock' => $domain->salesHidesOutOfStock(),
            ],
            'loyaltyRedemptionSettings' => [
                'points_per_currency_unit' => (float) config('loyalty.points_per_currency_unit', 100),
                'max_redemption_percent_of_eligible_net' => (float) config('loyalty.max_redemption_percent_of_eligible_net', 50),
                'min_points_redemption' => (int) config('loyalty.min_points_redemption', 1),
            ],
        ]);
    }

    public function products(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            // Barcode lookups must find an item even when the list hides out-of-stock products.
            'include_out_of_stock' => ['nullable', 'boolean'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 30), 100);
        $page = max(1, (int) ($validated['page'] ?? 1));

        $location = Helpers::getActiveLocation(
            $domain,
            $validated['location_id'] ?? $request->input('location_id')
        );

        if (! $location) {
            return ProductResource::collection(collect())->additional([
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }

        $base = Product::query()
            ->where('domain', $domain->name_slug)
            ->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('location_id', $location->id);
            })
            ->when($request->input('search'), fn ($q, $search) => $q->search($search))
            ->when($request->input('category'), function ($q, $category) {
                $q->whereHas('category', fn ($q) => $q->where('name', $category));
            })
            ->with([
                'category',
                'inventories' => fn ($q) => $q->where('location_id', $location->id),
                'modifierGroups.activeModifiers',
            ]);

        // Filtered before counting so the page count matches what is shown.
        if ($domain->salesHidesOutOfStock() && ! $request->boolean('include_out_of_stock')) {
            $base->where(function ($q) use ($location) {
                $q->where('track_inventory', false)
                    ->orWhereHas('inventories', fn ($iq) => $iq
                        ->where('location_id', $location->id)
                        ->where('quantity_available', '>', 0));
            });
        }

        $total = (clone $base)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $products = (clone $base)
            ->orderBy('name')->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        return ProductResource::collection($products)->additional([
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * Paginated product list for offline catalog sync (all sellable SKUs at active location).
     * Does not use the 30-item default cap from sales.products.
     */
    public function offlineCatalog(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 200), 250);
        $page = max(1, (int) ($validated['page'] ?? 1));

        $location = Helpers::getActiveLocation($domain, $validated['location_id'] ?? null);

        if (! $location) {
            return ProductResource::collection(collect())->additional([
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }

        $base = Product::query()
            ->where('domain', $domain->name_slug)
            ->whereHas('activeLocations', function ($q) use ($location) {
                $q->where('location_id', $location->id);
            })
            ->with([
                'category',
                'inventories' => fn ($q) => $q->where('location_id', $location->id),
                'modifierGroups.activeModifiers',
            ]);

        $total = (clone $base)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $products = (clone $base)
            ->orderBy('name')->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        return ProductResource::collection($products)->additional([
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function patchLoyaltyRedemption(Request $request, Domain $domain, Sale $sale): JsonResponse
    {
        $this->ensureSaleBelongsToDomain($sale, $domain);

        $validated = $request->validate([
            'loyalty_points' => ['required', 'integer', 'min:0'],
            'customer_id' => [
                'nullable',
                'exists:customers,id',
            ],
        ]);

        $points = (int) $validated['loyalty_points'];
        $customer = null;

        if ($points > 0) {
            if (empty($validated['customer_id'])) {
                throw ValidationException::withMessages([
                    'customer_id' => __('Select a customer to redeem loyalty points.'),
                ]);
            }

            $customer = Customer::query()->findOrFail($validated['customer_id']);

            if ($customer->domain !== $domain->name_slug) {
                abort(403, 'Customer does not belong to this organization.');
            }
        }

        try {
            app(LoyaltyRedemptionService::class)->syncPendingRedemption($domain, $sale, $customer, $points);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        $sale->refresh();

        return response()->json([
            'success' => true,
            'sale' => $sale->fresh(),
        ]);
    }

    public function proceedPayment(Request $request, Domain $domain, Sale $sale)
    {
        $this->ensureSaleBelongsToDomain($sale, $domain);

        $validated = $request->validate([
            'customer_id' => ['nullable', $this->customerInDomainRule($domain)],
            'sale_amount' => 'nullable|numeric|min:0',
            'loyalty_points_to_redeem' => 'nullable|integer|min:0',
            'payment_method' => 'required|string|in:cash,card,e-wallet,bank,credit,split',
            ...$this->splitPaymentRules(allowCredit: true),
        ]);

        $location = Helpers::getActiveLocation($domain);
        $isSplit = $validated['payment_method'] === 'split';

        // Card, e-wallet and bank payments name the channel used (terminal, GCash, BDO…) at this store.
        if ($isSplit) {
            $validated['payments'] = $this->validatedSplitPayments(
                $validated['payments'],
                $request->input('payments', []),
                $domain,
                $sale->location_id ?? $location?->id,
            );
        } else {
            $validated = array_merge($validated, $this->validatedPaymentChannel(
                $request->all(),
                $domain,
                $validated['payment_method'],
                $sale->location_id ?? $location?->id,
            ));
        }

        $validated['customer_id'] ??= null;

        $loyaltyResults = null;
        $creditResults = null;
        $redemptionService = app(LoyaltyRedemptionService::class);

        try {
            DB::transaction(function () use (
                $sale,
                $validated,
                $isSplit,
                &$loyaltyResults,
                &$creditResults,
                $location,
                $domain,
                $redemptionService,
            ) {
                $sale->refresh();

                $redeemPoints = (int) ($validated['loyalty_points_to_redeem'] ?? 0);

                if ($sale->payment_status !== 'pending') {
                    throw ValidationException::withMessages(['sale' => 'Sale is not pending.']);
                }

                if (! $sale->saleItems()->exists()) {
                    throw ValidationException::withMessages(['sale' => 'Add at least one item before taking payment.']);
                }

                // Planned loyalty redemption (updates sale totals) before deducting inventory
                if ($redeemPoints > 0) {
                    if (empty($validated['customer_id'])) {
                        throw ValidationException::withMessages(['customer_id' => 'Customer is required to redeem loyalty points.']);
                    }

                    $customerForRedemption = Customer::query()->findOrFail($validated['customer_id']);

                    $redemptionService->applyPendingForCheckout($sale, $customerForRedemption, $redeemPoints);

                    $sale->refresh();

                    if ((int) $sale->loyalty_points_redeemed > 0) {
                        $customerForRedemption->redeemPoints((int) $sale->loyalty_points_redeemed);
                    }
                } elseif ((int) $sale->loyalty_points_redeemed > 0) {
                    $sale->update([
                        'loyalty_points_redeemed' => 0,
                        'loyalty_discount_amount' => 0,
                    ]);
                    $sale->recalcTotals();
                }

                // Re-total with the current VAT settings: they may have changed since the cart was last touched.
                $sale->recalcTotals();

                // A split payment has to cover the total, now that the total is final.
                $splitParts = $isSplit
                    ? $this->settleSplitPayments($validated['payments'], (float) $sale->fresh()->grand_total)
                    : [];

                // 1. Complete sale and process inventory
                $sale->refresh();
                $this->saleService->completeSale($sale, auth()->user());

                // 2. Handle overselling situations (create automatic stock adjustments)
                $oversoldItems = [];
                if ($this->saleService->oversellingAdjustmentEnabled($sale->domain)) {
                    $oversoldItems = $this->saleService->handleOverselling($sale);
                }
                if (! empty($oversoldItems)) {
                    \Log::info('Oversell detected during sale completion', [
                        'sale_id' => $sale->id,
                        'invoice_number' => $sale->invoice_number,
                        'oversold_count' => count($oversoldItems),
                    ]);
                }

                // 3. A sale paid in parts keeps each part; a credit part goes on the customer's account.
                if ($isSplit) {
                    $customer = $validated['customer_id'] ? Customer::findOrFail($validated['customer_id']) : null;
                    $creditPart = collect($splitParts)->firstWhere('method', 'credit');

                    if ($creditPart) {
                        if (! $customer) {
                            throw ValidationException::withMessages(['customer_id' => 'Customer is required for credit payments.']);
                        }

                        $creditTransaction = $this->creditService->processCreditSale($sale, $customer, (float) $creditPart['amount']);

                        $creditResults = [
                            'transaction_id' => $creditTransaction->id,
                            'credit_balance' => $customer->fresh()->credit_balance,
                            'available_credit' => $customer->fresh()->getAvailableCredit(),
                        ];
                    }

                    $sale->refresh();
                    $sale->update([
                        'payment_method' => 'split',
                        'payment_card_type_id' => null,
                        'payment_reference' => null,
                        'location_id' => $location->id,
                        'payment_status' => 'paid',
                    ]);
                    $sale->payments()->createMany($splitParts);

                    if ($customer) {
                        $sale->updateCustomer($customer->id);
                        $loyaltyResults = $customer->processLoyaltyForSale((float) $sale->fresh()->grand_total);
                    }
                } elseif ($validated['payment_method'] === 'credit') {
                    if (! $validated['customer_id']) {
                        throw ValidationException::withMessages(['customer_id' => 'Customer is required for credit payments.']);
                    }

                    $customer = Customer::findOrFail($validated['customer_id']);

                    $saleAmount = (float) $sale->fresh()->grand_total;

                    // Validate credit limit
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

                    $loyaltyResults = $customer->processLoyaltyForSale($saleAmount);
                } else {
                    // 4. Update payment details for non-credit payments (preserve grand_total incl. VAT)
                    $sale->refresh();
                    $sale->update([
                        'payment_method' => $validated['payment_method'],
                        'payment_card_type_id' => $validated['payment_card_type_id'] ?? null,
                        'payment_reference' => $validated['payment_reference'] ?? null,
                        'location_id' => $location->id,
                        'payment_status' => 'paid',
                    ]);

                    // 5. Process loyalty if customer is provided (earn points on net amount paid)
                    if ($validated['customer_id']) {
                        $customer = Customer::findOrFail($validated['customer_id']);

                        // Link customer to sale and trigger order update event
                        $sale->updateCustomer($customer->id);

                        $earnAmount = (float) $sale->fresh()->grand_total;

                        $loyaltyResults = $customer->processLoyaltyForSale($earnAmount);
                    }
                }
            });
        } catch (InsufficientStockException $e) {
            $names = collect($e->getUnavailableItems())->pluck('product_name')->implode(', ');

            return response()->json([
                'success' => false,
                'errors' => ['stock' => ["Insufficient stock for: {$names}"]],
                'message' => "Insufficient stock for: {$names}",
            ], 422);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed: '.$e->getMessage(),
            ], 500);
        }

        // Trigger payment completed event to clear the order view
        event(new PaymentCompleted($sale));

        // Return response with loyalty and credit results
        return response()->json([
            'success' => true,
            'message' => 'Payment processed successfully',
            'loyalty_results' => $loyaltyResults,
            'credit_results' => $creditResults,
        ]);
    }

    protected function ensureSaleBelongsToDomain(Sale $sale, Domain $domain): void
    {
        if ($sale->domain && $sale->domain !== $domain->name_slug) {
            abort(404);
        }
    }

    /** `exists` rule limited to this organization's customers. */
    /**
     * For card, e-wallet and bank payments: the channel used (a card terminal, GCash, BDO…), which
     * must be an active channel of that kind at the sale's store, plus an optional reference number.
     * Cash and credit carry neither.
     *
     * @return array{payment_card_type_id: ?int, payment_reference: ?string}
     */
    protected function validatedPaymentChannel(array $input, Domain $domain, string $method, ?int $locationId): array
    {
        $kind = PaymentCardType::KIND_FOR_METHOD[$method] ?? null;
        if (! $kind) {
            return ['payment_card_type_id' => null, 'payment_reference' => null];
        }

        $label = ['card' => 'card type', 'ewallet' => 'e-wallet', 'bank' => 'bank'][$kind];

        $validated = Validator::make($input, [
            'payment_card_type_id' => [
                'required',
                'integer',
                Rule::exists('payment_card_types', 'id')->where(function ($q) use ($domain, $kind, $locationId) {
                    $q->where('domain', $domain->name_slug)
                        ->where('kind', $kind)
                        ->where('is_active', true)
                        ->when($locationId, fn ($q) => $q->where('location_id', $locationId));
                }),
            ],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ], [
            'payment_card_type_id.required' => "Choose the {$label} used for this payment.",
            'payment_card_type_id.exists' => "That {$label} isn't available at this store.",
        ])->validate();

        $reference = trim((string) ($validated['payment_reference'] ?? ''));

        return [
            'payment_card_type_id' => (int) $validated['payment_card_type_id'],
            // Card payments have no reference to keep.
            'payment_reference' => $kind !== 'card' && $reference !== '' ? $reference : null,
        ];
    }

    /** Rules for the parts of a split payment (2 to 4 of them); offline sales cannot put any on credit. */
    protected function splitPaymentRules(bool $allowCredit, string $prefix = ''): array
    {
        $methods = $allowCredit ? 'cash,card,e-wallet,bank,credit' : 'cash,card,e-wallet,bank';

        return [
            "{$prefix}payments" => ["required_if:{$prefix}payment_method,split", 'array', 'min:2', 'max:4'],
            "{$prefix}payments.*.method" => ['required', 'string', "in:{$methods}"],
            "{$prefix}payments.*.amount" => ['required', 'numeric', 'min:0.01'],
            "{$prefix}payments.*.payment_card_type_id" => ['nullable', 'integer'],
            "{$prefix}payments.*.payment_reference" => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Each part of a split payment with the channel it went through, checked as a single payment would be.
     * Cash and credit can each be used once; card, e-wallet and bank more than once (GCash + Maya).
     *
     * @return list<array{method: string, payment_card_type_id: ?int, reference: ?string, amount: float}>
     */
    protected function validatedSplitPayments(array $parts, array $rawParts, Domain $domain, ?int $locationId, string $errorPrefix = ''): array
    {
        $rows = [];
        foreach (array_values($parts) as $i => $part) {
            try {
                $channel = $this->validatedPaymentChannel($rawParts[$i] ?? $part, $domain, $part['method'], $locationId);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(
                    collect($e->errors())->mapWithKeys(fn ($m, $k) => ["{$errorPrefix}payments.{$i}.{$k}" => $m])->all()
                );
            }

            $rows[] = [
                'method' => $part['method'],
                'payment_card_type_id' => $channel['payment_card_type_id'],
                'reference' => $channel['payment_reference'],
                'amount' => round((float) $part['amount'], 2),
            ];
        }

        $uses = collect($rows)->countBy('method');
        if (($uses['cash'] ?? 0) > 1 || ($uses['credit'] ?? 0) > 1) {
            throw ValidationException::withMessages([
                "{$errorPrefix}payments" => 'Cash and credit can each be used once in a split payment.',
            ]);
        }

        return $rows;
    }

    /**
     * Check a split payment against the sale's final total. Only cash may come to more than what is left
     * (the rest is change), so the cash part is kept as what it paid toward the sale, with what was handed over.
     *
     * @param  list<array{method: string, payment_card_type_id: ?int, reference: ?string, amount: float}>  $rows
     * @return list<array{method: string, payment_card_type_id: ?int, reference: ?string, amount: float, tendered: ?float}>
     */
    protected function settleSplitPayments(array $rows, float $grandTotal, string $errorPrefix = ''): array
    {
        $cents = fn ($v) => (int) round((float) $v * 100);
        $total = $cents($grandTotal);
        $nonCash = collect($rows)->where('method', '!=', 'cash')->sum(fn ($r) => $cents($r['amount']));
        $cash = collect($rows)->where('method', 'cash')->sum(fn ($r) => $cents($r['amount']));
        $peso = fn (int $c) => '₱'.number_format($c / 100, 2);

        if ($nonCash > $total) {
            throw ValidationException::withMessages([
                "{$errorPrefix}payments" => "Card, e-wallet, bank and credit come to {$peso($nonCash)}, more than the {$peso($total)} total. Only cash can be more, for change.",
            ]);
        }

        if ($nonCash + $cash < $total) {
            throw ValidationException::withMessages([
                "{$errorPrefix}payments" => "The payments come to {$peso($nonCash + $cash)}, short of the {$peso($total)} total.",
            ]);
        }

        $settled = [];
        foreach ($rows as $row) {
            if ($row['method'] === 'cash') {
                $applied = $total - $nonCash;
                // Cash that only went to change paid nothing toward the sale.
                if ($applied <= 0) {
                    continue;
                }
                $settled[] = [...$row, 'amount' => $applied / 100, 'tendered' => $row['amount']];
            } else {
                $settled[] = [...$row, 'tendered' => null];
            }
        }

        return $settled;
    }

    protected function customerInDomainRule(Domain $domain)
    {
        return Rule::exists('customers', 'id')->where('domain', $domain->name_slug);
    }

    /** `exists` rule limited to this organization's products. */
    protected function productInDomainRule(Domain $domain)
    {
        return Rule::exists('products', 'id')->where('domain', $domain->name_slug);
    }

    /**
     * The user whose cart a users/{user}/sales route acts on. Must belong to this organization:
     * otherwise a cart (and sale) would be created for another organization's user.
     */
    protected function cartUserInDomain(Request $request, Domain $domain): User
    {
        $user = User::findOrFail($request->route('user'));

        abort_if($user->domain !== $domain->name_slug, 404);

        return $user;
    }

    public function storeDraft(Request $request, Domain $domain)
    {
        $user = $request->user();
        $userRole = $user->roles()->first();

        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = Helpers::getActiveLocation($domain);
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
            'product_id' => ['required', $this->productInDomainRule($domain)],
            'quantity' => 'required|integer|min:1',
            ...self::LINE_OPTION_RULES,
        ]);

        $this->ensureSaleBelongsToDomain($sale, $domain);

        $saleId = $sale->id;

        $saleItemId = null;

        DB::transaction(function () use ($validated, $domain, $saleId, &$saleItemId) {
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();
            $this->ensureSaleBelongsToDomain($sale, $domain);

            // Priced from the database (product and options), never from the request.
            $saleItemId = app(ProductModifierService::class)->addToSale(
                $sale,
                Product::findOrFail($validated['product_id']),
                (int) $validated['quantity'],
                $validated['modifier_ids'] ?? [],
                $validated['notes'] ?? null,
            )->id;

            $sale->recalcTotals();

            $this->saleService->enforceNoOversellForPendingSale($domain, $sale->fresh());
        });

        $sale->refresh()->load(['saleItems.product', 'saleItems.modifiers', 'saleDiscounts']);
        $saleItem = $sale->saleItems()->with(['product', 'modifiers'])->find($saleItemId);

        return response()->json([
            'success' => true,
            'item' => $saleItem,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
        ]);
    }

    /**
     * Remove item from cart
     */
    public function removeItemFromCart(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'product_id' => ['required', $this->productInDomainRule($domain)],
            ...self::LINE_TARGET_RULES,
        ]);

        $this->cartLines($sale, $validated)->delete();
        $sale->recalcTotals();

        // Refresh the sale with all relationships
        $sale->load(['saleItems.product', 'saleItems.modifiers', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
        ]);
    }

    /**
     * Update item quantity in cart
     */
    public function updateItemQuantity(Request $request, Domain $domain, Sale $sale)
    {
        $validated = $request->validate([
            'product_id' => ['required', $this->productInDomainRule($domain)],
            'quantity' => 'required|integer|min:1',
            ...self::LINE_TARGET_RULES,
        ]);

        $this->ensureSaleBelongsToDomain($sale, $domain);
        $saleId = $sale->id;

        DB::transaction(function () use ($validated, $domain, $saleId) {
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();
            $this->ensureSaleBelongsToDomain($sale, $domain);

            $saleItem = $this->cartLines($sale, $validated)->firstOrFail();

            $saleItem->update(['quantity' => $validated['quantity']]);
            $sale->recalcTotals();

            $this->saleService->enforceNoOversellForPendingSale($domain, $sale->fresh());
        });

        $sale->refresh()->load(['saleItems.product', 'saleItems.modifiers', 'saleDiscounts']);
        $saleItem = $this->cartLines($sale, $validated)->firstOrFail();
        $saleItem->load(['product', 'modifiers']);

        return response()->json([
            'success' => true,
            'item' => $saleItem,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
        ]);
    }

    /**
     * Get current cart state
     */
    public function getCartState(Request $request, Domain $domain, Sale $sale)
    {
        $sale->load(['saleItems.product', 'saleItems.modifiers', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
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
        $mandatoryDiscounts = MandatoryDiscount::where('is_active', true)
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
            ...self::LINE_TARGET_RULES,
        ]);

        $saleItem = $this->saleService->voidItem($sale, $validated, auth()->user());

        return response()->json([
            'message' => 'Sale item voided successfully.',
            'item' => $saleItem,
        ]);
    }

    public function findSaleItem(Request $request, Domain $domain, Sale $sale)
    {
        return $this->cartLines($sale, $request->only(['product_id', 'sale_item_id']))
            ->with(['discounts', 'modifiers'])
            ->first();
    }

    /**
     * The cart line(s) a request is about: the line named by sale_item_id, or, for callers that only
     * know the product, that product's lines.
     *
     * @param  array{product_id?: mixed, sale_item_id?: mixed}  $target
     */
    protected function cartLines(Sale $sale, array $target)
    {
        return $sale->saleItems()
            ->when(
                $target['sale_item_id'] ?? null,
                fn ($q, $id) => $q->whereKey((int) $id),
                fn ($q) => $q->where('product_id', $target['product_id'] ?? 0)->orderBy('id')
            );
    }

    public function assignCustomer(Request $request, Domain $domain, Sale $sale)
    {
        $this->ensureSaleBelongsToDomain($sale, $domain);

        $validated = $request->validate([
            'customer_id' => ['nullable', $this->customerInDomainRule($domain)],
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
            $customer = Customer::findOrFail($validated['customer_id']);
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
        event(new CustomerUpdated($sale));

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
        event(new OrderUpdated($sale->fresh([
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
            $location = Helpers::getActiveLocation($domain);
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
            'totals' => $sale ? $this->saleTotalsPayload($sale) : $this->emptySaleTotalsPayload(),
        ]);
    }

    /**
     * Create a new sale for a specific user
     */
    public function createSaleForUser(Request $request, Domain $domain, $userId)
    {
        $user = $this->cartUserInDomain($request, $domain);

        // Determine location based on current user's role
        $currentUser = auth()->user();
        $userRole = $currentUser ? $currentUser->roles()->first() : null;

        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use Helpers::getActiveLocation()
            $location = Helpers::getActiveLocation($domain);
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

        $user = $this->cartUserInDomain($request, $domain);

        $currentUser = auth()->user();
        $userRole = $currentUser->roles()->first();

        $query = Sale::forDomain($domain->name_slug)
            ->pending()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = Helpers::getActiveLocation($domain);
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
                $location = Helpers::getActiveLocation($domain);
                $locationId = $location?->id;
            } else {
                // Regular users: Use their assigned location
                $locationId = $user->location_id;
            }

            $sale = $this->saleService->storeDraft($user, $locationId);
        }

        // Now add item to the sale
        $validated = $request->validate([
            'product_id' => ['required', $this->productInDomainRule($domain)],
            'quantity' => 'required|integer|min:1',
            ...self::LINE_OPTION_RULES,
        ]);

        $saleId = $sale->id;

        DB::transaction(function () use ($validated, $domain, $saleId) {
            $saleLocked = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();

            // Priced from the database (product and options), never from the request.
            app(ProductModifierService::class)->addToSale(
                $saleLocked,
                Product::findOrFail($validated['product_id']),
                (int) $validated['quantity'],
                $validated['modifier_ids'] ?? [],
                $validated['notes'] ?? null,
            );

            $saleLocked->recalcTotals();

            $this->saleService->enforceNoOversellForPendingSale($domain, $saleLocked->fresh());
        });

        $sale = Sale::query()
            ->whereKey($saleId)
            ->with(['saleItems.product', 'saleItems.modifiers', 'saleDiscounts'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
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
            ->with(['saleItems.product', 'saleItems.modifiers', 'saleItems.discounts', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = Helpers::getActiveLocation($domain);
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

        $mandatoryDiscounts = MandatoryDiscount::where('is_active', true)
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
            'totals' => $sale ? $this->saleTotalsPayload($sale) : $this->emptySaleTotalsPayload(),
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
            $location = Helpers::getActiveLocation($domain);
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
            'product_id' => ['required', $this->productInDomainRule($domain)],
            'quantity' => 'required|integer|min:1',
            ...self::LINE_TARGET_RULES,
        ]);

        $saleId = $sale->id;

        DB::transaction(function () use ($validated, $domain, $saleId) {
            $saleLocked = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();

            $saleItem = $this->cartLines($saleLocked, $validated)->firstOrFail();

            $saleItem->update(['quantity' => $validated['quantity']]);
            $saleLocked->recalcTotals();

            $this->saleService->enforceNoOversellForPendingSale($domain, $saleLocked->fresh());
        });

        $sale->refresh()->load(['saleItems.product', 'saleItems.modifiers', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
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

        // Apply location-based filtering based on user role (same as updateUserCartQuantity)
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = Helpers::getActiveLocation($domain);
            if ($location) {
                $query->where('location_id', $location->id);
            }
            // Always scope to target user, or this would edit whichever pending sale is newest
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
            'product_id' => ['required', $this->productInDomainRule($domain)],
            ...self::LINE_TARGET_RULES,
        ]);

        $this->cartLines($sale, $validated)->delete();
        $sale->recalcTotals();

        $sale->load(['saleItems.product', 'saleItems.modifiers', 'saleDiscounts']);

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
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
            ->with(['saleItems.product', 'saleItems.modifiers', 'saleItems.discounts', 'saleDiscounts.discount', 'saleDiscounts.mandatoryDiscount']);

        // Apply location-based filtering based on user role
        if ($userRole && ($userRole->name === 'admin' || $userRole->name === 'super admin')) {
            // Admin/Super Admin: Use active location
            $location = Helpers::getActiveLocation($domain);
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
                'totals' => $this->emptySaleTotalsPayload(),
            ]);
        }

        return response()->json([
            'success' => true,
            'sale' => $sale,
            'items' => $sale->saleItems,
            'discounts' => $sale->saleDiscounts,
            'totals' => $this->saleTotalsPayload($sale),
        ]);
    }

    /**
     * Offline sales capture + review (Dexie on the client; this page is Inertia shell + props).
     */
    public function offlineTransactionsPage(Request $request, Domain $domain)
    {
        $locations = InventoryLocation::forDomain($domain->name_slug)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default']);

        $activeLocation = Helpers::getActiveLocation($domain);

        return Inertia::render('Sales/OfflineTransactions', [
            'domain' => $domain,
            'locations' => $locations,
            'activeLocationId' => $activeLocation?->id,
        ]);
    }

    /**
     * Idempotent replay of one or more offline-finalized sales (client mutation IDs).
     */
    public function offlineSync(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'sales' => ['required', 'array', 'min:1'],
            'sales.*.client_mutation_id' => ['required', 'uuid'],
            'sales.*.payload' => ['required', 'array'],
            'sales.*.payload.items' => ['required', 'array', 'min:1'],
            'sales.*.payload.items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'sales.*.payload.items.*.quantity' => ['required', 'integer', 'min:1'],
            'sales.*.payload.items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'sales.*.payload.items.*.modifier_ids' => ['nullable', 'array', 'max:30'],
            'sales.*.payload.items.*.modifier_ids.*' => ['integer'],
            'sales.*.payload.items.*.notes' => ['nullable', 'string', 'max:200'],
            'sales.*.payload.payment_method' => ['required', 'string', 'in:cash,card,e-wallet,bank,split'],
            ...$this->splitPaymentRules(allowCredit: false, prefix: 'sales.*.payload.'),
            'sales.*.payload.location_id' => ['required', 'integer'],
            'sales.*.payload.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sales.*.payload.notes' => ['nullable', 'string', 'max:2000'],
            'sales.*.payload.recorded_at' => ['nullable', 'date'],
            'sales.*.payload.cashier_user_id' => ['required', 'integer', 'exists:users,id'],
            'sales.*.payload.payment_card_type_id' => ['nullable', 'integer'],
            'sales.*.payload.payment_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $results = [];

        foreach ($validated['sales'] as $entry) {
            $mutationId = $entry['client_mutation_id'];
            $payload = $entry['payload'];

            try {
                $results[] = $this->processOneOfflineSaleSync($domain, $mutationId, $payload);
            } catch (ValidationException $e) {
                $first = collect($e->errors())->flatten()->first();
                $results[] = [
                    'client_mutation_id' => $mutationId,
                    'success' => false,
                    'message' => $first ?: $e->getMessage() ?: 'Validation failed.',
                    'errors' => $e->errors(),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'client_mutation_id' => $mutationId,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    /**
     * @return array{client_mutation_id: string, success: bool, sale_id?: int, duplicate?: bool, message?: string}
     */
    private function processOneOfflineSaleSync(Domain $domain, string $clientMutationId, array $payload): array
    {
        $location = InventoryLocation::forDomain($domain->name_slug)
            ->where('id', $payload['location_id'])
            ->where('is_active', true)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => ['Invalid or inactive location for this organization.'],
            ]);
        }
        if ($location->isWarehouse()) {
            throw ValidationException::withMessages([
                'location_id' => [EnsureSellableLocation::MESSAGE],
            ]);
        }

        $cashier = User::findOrFail((int) $payload['cashier_user_id']);
        if (! $cashier->isSuperUser() && $cashier->domain !== $domain->name_slug) {
            throw ValidationException::withMessages([
                'cashier_user_id' => ['Cashier does not belong to this organization.'],
            ]);
        }

        $productIds = collect($payload['items'])->pluck('product_id')->unique()->values();
        $domainProductCount = Product::query()
            ->where('domain', $domain->name_slug)
            ->whereIn('id', $productIds)
            ->count();

        if ($domainProductCount !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['One or more products are invalid for this organization.'],
            ]);
        }

        if (! empty($payload['customer_id'])) {
            $customerOk = Customer::query()
                ->where('id', $payload['customer_id'])
                ->where('domain', $domain->name_slug)
                ->exists();

            if (! $customerOk) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Customer is invalid for this organization.'],
                ]);
            }
        }

        $isSplit = ($payload['payment_method'] ?? 'cash') === 'split';
        $splitRows = $isSplit
            ? $this->validatedSplitPayments($payload['payments'], $payload['payments'], $domain, $location->id)
            : [];
        $channel = $isSplit
            ? ['payment_card_type_id' => null, 'payment_reference' => null]
            : $this->validatedPaymentChannel($payload, $domain, $payload['payment_method'] ?? 'cash', $location->id);

        if (! $domain->salesAllowsOverselling()) {
            $stockItems = collect($payload['items'])->map(function (array $row) {
                return [
                    'product_id' => (int) $row['product_id'],
                    'quantity' => (int) $row['quantity'],
                ];
            })->all();

            $short = $this->inventoryService->checkStockAvailability($stockItems, $location);
            if (! empty($short)) {
                $names = collect($short)->pluck('product_name')->implode(', ');

                throw ValidationException::withMessages([
                    'stock' => ["Insufficient stock for: {$names}"],
                ]);
            }
        }

        return DB::transaction(function () use ($domain, $clientMutationId, $payload, $location, $cashier, $channel, $isSplit, $splitRows) {
            $syncRow = null;

            try {
                $syncRow = OfflineSaleSync::query()->create([
                    'user_id' => auth()->id(),
                    'domain' => $domain->name_slug,
                    'client_mutation_id' => $clientMutationId,
                    'sale_id' => null,
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                $existing = OfflineSaleSync::query()
                    ->where('domain', $domain->name_slug)
                    ->where('client_mutation_id', $clientMutationId)
                    ->first();

                if ($existing && $existing->sale_id) {
                    return [
                        'client_mutation_id' => $clientMutationId,
                        'success' => true,
                        'sale_id' => $existing->sale_id,
                        'duplicate' => true,
                    ];
                }

                throw ValidationException::withMessages([
                    'client_mutation_id' => ['This sale is already being synced. Retry in a moment.'],
                ]);
            }

            try {
                $transactionDate = isset($payload['recorded_at'])
                    ? Carbon::parse($payload['recorded_at'])
                    : now();

                $sale = Sale::query()->create([
                    'user_id' => $cashier->id,
                    'location_id' => $location->id,
                    'domain' => $domain->name_slug,
                    'payment_status' => 'pending',
                    'invoice_number' => Str::upper(Str::random(10)),
                    'transaction_date' => $transactionDate,
                    'customer_id' => $payload['customer_id'] ?? null,
                    'payment_method' => $payload['payment_method'],
                    'payment_card_type_id' => $channel['payment_card_type_id'],
                    'payment_reference' => $channel['payment_reference'],
                    'notes' => $payload['notes'] ?? null,
                    'tax_amount' => 0,
                ]);

                foreach ($payload['items'] as $row) {
                    // Lines with options (or a note) are priced here from the product and its options.
                    if (! empty($row['modifier_ids']) || ! empty($row['notes'])) {
                        app(ProductModifierService::class)->addToSale(
                            $sale,
                            Product::findOrFail((int) $row['product_id']),
                            (int) $row['quantity'],
                            $row['modifier_ids'] ?? [],
                            $row['notes'] ?? null,
                        );

                        continue;
                    }

                    $sale->saleItems()->create([
                        'product_id' => (int) $row['product_id'],
                        'quantity' => (int) $row['quantity'],
                        'unit_price' => (float) $row['unit_price'],
                    ]);
                }

                $sale->recalcTotals();

                $splitParts = $isSplit
                    ? $this->settleSplitPayments($splitRows, (float) $sale->fresh()->grand_total)
                    : [];

                $this->saleService->completeSale($sale, $cashier, $location);
                if ($this->saleService->oversellingAdjustmentEnabled($domain->name_slug)) {
                    $this->saleService->handleOverselling($sale);
                }

                $sale->refresh();
                $sale->update([
                    'payment_method' => $payload['payment_method'],
                    'payment_card_type_id' => $channel['payment_card_type_id'],
                    'payment_reference' => $channel['payment_reference'],
                    'location_id' => $location->id,
                    'payment_status' => 'paid',
                ]);
                if ($splitParts !== []) {
                    $sale->payments()->createMany($splitParts);
                }

                OfflineSaleSync::query()
                    ->whereKey($syncRow->id)
                    ->update(['sale_id' => $sale->id]);

                event(new PaymentCompleted($sale));

                return [
                    'client_mutation_id' => $clientMutationId,
                    'success' => true,
                    'sale_id' => $sale->id,
                ];
            } catch (\Throwable $e) {
                if ($syncRow) {
                    OfflineSaleSync::query()->whereKey($syncRow->id)->delete();
                }

                throw $e;
            }
        });
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

        return $sqlState === '23000'
            || $code === 1062 // MySQL duplicate
            || $code === 19 // SQLite UNIQUE constraint
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /**
     * @return array{subtotal: float, discount_amount: float, tax_amount: float, grand_total: float}
     */
    protected function saleTotalsPayload(Sale $sale): array
    {
        return [
            'subtotal' => (float) $sale->total_amount,
            'discount_amount' => (float) $sale->discount_amount,
            'tax_amount' => (float) $sale->tax_amount,
            'grand_total' => (float) $sale->grand_total,
        ];
    }

    /**
     * @return array{subtotal: int, discount_amount: int, tax_amount: int, grand_total: int}
     */
    protected function emptySaleTotalsPayload(): array
    {
        return [
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 0,
        ];
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
