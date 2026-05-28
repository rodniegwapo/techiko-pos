<?php

use App\Http\Controllers\Domains\CategoryController;
use App\Http\Controllers\Domains\CreditController;
use App\Http\Controllers\Domains\CustomerController;
use App\Http\Controllers\Domains\DashboardController;
use App\Http\Controllers\Domains\DomainSettingsController;
use App\Http\Controllers\Domains\Inventory\InventoryController;
use App\Http\Controllers\Domains\Inventory\InventoryLocationController;
use App\Http\Controllers\Domains\Inventory\StockAdjustmentController;
use App\Http\Controllers\Domains\LoyaltyController;
use App\Http\Controllers\Domains\LoyaltyTierController;
use App\Http\Controllers\Domains\ManualBillingController;
use App\Http\Controllers\Domains\PaymentCardTypeController;
use App\Http\Controllers\Domains\PayMongoQrPhController;
use App\Http\Controllers\Domains\ProductController;
use App\Http\Controllers\Domains\SaleController;
use App\Http\Controllers\Domains\SaleDiscountController;
use App\Http\Controllers\Domains\SharedCatalogLookupController;
use App\Http\Controllers\Domains\UserController;
use App\Http\Controllers\Domains\UserPinController;
use App\Http\Controllers\Domains\VatReportController;
use App\Http\Controllers\Domains\WalletCashMovementController;
use App\Http\Controllers\MandatoryDiscountController;
use App\Http\Controllers\Products\DiscountController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\VoidLogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('domains/{domain:name_slug}')
    ->middleware(['auth', 'verified', 'user.permission', 'role.access'])
    ->name('domains.')
    ->group(function () {
        // Organization settings (Sales VAT, etc.)
        Route::get('/settings', [DomainSettingsController::class, 'index'])->name('settings.index');
        Route::patch('/settings', [DomainSettingsController::class, 'update'])->name('settings.update');

        Route::get('/billing/gcash', function (Request $request) {
            $domain = $request->route('domain');

            return redirect()->route('domains.billing.servicing.index', [
                'domain' => is_string($domain) ? $domain : $domain->name_slug,
            ], 302);
        })->name('billing.gcash.legacy');

        Route::get('/billing/servicing', [ManualBillingController::class, 'index'])->name('billing.servicing.index');
        Route::post('/billing/servicing/manual-gcash', [ManualBillingController::class, 'store'])->name('billing.servicing.manual_gcash');
        Route::post('/billing/paymongo/qrph', [PayMongoQrPhController::class, 'store'])->name('billing.paymongo.qrph.store');
        Route::get('/billing/paymongo/status', [PayMongoQrPhController::class, 'status'])->name('billing.paymongo.status');

        // Dashboard (Organization-specific)
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Dashboard API routes (Organization-specific)
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::post('/sales-chart', [DashboardController::class, 'getSalesChartData'])->name('sales-chart');
        });

        // Sales (Organization-specific)
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('/sales/products', [SaleController::class, 'products'])->name('sales.products');
        Route::get('/sales/offline-catalog', [SaleController::class, 'offlineCatalog'])->name('sales.offline-catalog');

        // User-specific sales routes (handles "no sales id yet" case)
        Route::prefix('users/{user}')->name('users.')->group(function () {
            // Create new sale for user (when no sales id yet)
            Route::post('/sales', [SaleController::class, 'createSaleForUser'])->name('sales.create');

            // Add item to user's latest pending sale (auto-finds or creates)
            Route::post('/sales/cart/add', [SaleController::class, 'addItemToUserCart'])->name('sales.cart.add');

            // Get user's current pending sale
            Route::get('/sales/current-pending', [SaleController::class, 'getUserPendingSale'])->name('sales.current-pending');

            // Other cart operations for user's latest sale
            Route::patch('/sales/cart/update-quantity', [SaleController::class, 'updateUserCartQuantity'])->name('sales.cart.update-quantity');
            Route::delete('/sales/cart/remove', [SaleController::class, 'removeFromUserCart'])->name('sales.cart.remove');
            Route::get('/sales/cart/state', [SaleController::class, 'getUserCartState'])->name('sales.cart.state');
        });

        // Sales API routes (Organization-specific)
        Route::prefix('sales')->name('sales.')->group(function () {
            Route::get('/offline-transactions', [SaleController::class, 'offlineTransactionsPage'])->name('offline-transactions');
            Route::post('/offline-sync', [SaleController::class, 'offlineSync'])->name('offline-sync');
            Route::get('/discounts/current', [SaleController::class, 'getCurrentDiscounts'])->name('discounts.current');

            Route::post('/draft', [SaleController::class, 'storeDraft'])->name('drafts.store');
            Route::get('/oversell-statistics', [SaleController::class, 'getOversellStatistics'])->name('oversell.statistics');

            // Scoped bindings
            Route::scopeBindings()->group(function () {
                Route::post('/{sale}/sales-items/void', [SaleController::class, 'voidItem'])->name('items.void');
                Route::post('/{sale}/payments', [SaleController::class, 'proceedPayment'])->name('payment.store');
                Route::patch('/{sale}/loyalty-redemption', [SaleController::class, 'patchLoyaltyRedemption'])->name('loyalty-redemption');
                // Cart management - database-driven
                Route::post('/{sale}/cart/add', [SaleController::class, 'addItemToCart'])->name('cart.add');
                Route::delete('/{sale}/cart/remove', [SaleController::class, 'removeItemFromCart'])->name('cart.remove');
                Route::patch('/{sale}/cart/update-quantity', [SaleController::class, 'updateItemQuantity'])->name('cart.update-quantity');
                Route::get('/{sale}/cart/state', [SaleController::class, 'getCartState'])->name('cart.state');

                // Discounts - database-driven
                Route::get('/{sale}/discounts', [SaleDiscountController::class, 'getSaleDiscounts'])->name('discounts.sale');
                Route::patch('/{sale}/discounts', [SaleDiscountController::class, 'updateSaleDiscounts'])->name('discounts.update');
                Route::delete('/{sale}/discounts', [SaleDiscountController::class, 'removeSaleDiscounts'])->name('discounts.remove');

                // Item-level discounts
                Route::post('/{sale}/items/{saleItem}/discounts', [SaleDiscountController::class, 'applyItemDiscount'])->name('items.discount.apply');
                Route::delete('/{sale}/items/{saleItem}/discounts', [SaleDiscountController::class, 'removeItemDiscount'])->name('items.discount.remove');
                Route::get('/{sale}/find-sale-item', [SaleController::class, 'findSaleItem'])->name('find-sale-item');
                Route::post('/{sale}/assign-customer', [SaleController::class, 'assignCustomer'])->name('sales.assignCustomer');
                Route::post('/{sale}/process-loyalty', [SaleController::class, 'processLoyalty'])->name('sales.processLoyalty');
                Route::post('/{sale}/test-order-event', [SaleController::class, 'testOrderEvent'])->name('sales.testOrderEvent');
            });
        });

        // Shared catalog barcode lookup (JSON)
        Route::get('/shared-catalog/lookup', [SharedCatalogLookupController::class, 'lookup'])->name('shared-catalog.lookup');

        // Products (Organization-specific)
        Route::get('products/assignable', [ProductController::class, 'assignable'])
            ->name('products.assignable');
        Route::post('products/{product}/attach-location', [ProductController::class, 'attachLocation'])
            ->name('products.attach-location');

        Route::resource('products', ProductController::class)
            ->only(['index', 'store', 'update', 'destroy', 'create', 'edit'])
            ->names('products');

        // Categories (Organization-specific)
        Route::resource('categories', CategoryController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('categories');

        // Product Discounts (Organization-specific)
        Route::name('products.')->group(function () {
            Route::resource('discounts', DiscountController::class)
                ->only(['index', 'store', 'update', 'destroy'])
                ->names('discounts');
        });

        // Mandatory Discounts (Organization-specific)
        Route::resource('mandatory-discounts', MandatoryDiscountController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('mandatory-discounts');

        // Loyalty Program (Organization-specific)
        Route::get('/loyalty', [LoyaltyController::class, 'index'])->name('loyalty.index');
        Route::get('/loyalty/stats', [LoyaltyController::class, 'stats'])->name('loyalty.stats');
        Route::get('/loyalty/customers', [LoyaltyController::class, 'customers'])->name('loyalty.customers');
        Route::get('/loyalty/analytics', [LoyaltyController::class, 'analytics'])->name('loyalty.analytics');
        Route::post('/loyalty/customers/{customer}/adjust-points', [LoyaltyController::class, 'adjustPoints'])->name('loyalty.adjust-points');

        // Tier Management (Organization-specific)
        Route::apiResource('loyalty/tiers', LoyaltyTierController::class)->names([
            'index' => 'loyalty.tiers.index',
            'store' => 'loyalty.tiers.store',
            'show' => 'loyalty.tiers.show',
            'update' => 'loyalty.tiers.update',
            'destroy' => 'loyalty.tiers.destroy',
        ]);

        // Customers (Organization-specific)
        Route::resource('customers', CustomerController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('customers');

        // VAT summary (output VAT from paid sales)
        Route::get('/vat-report/export-json', [VatReportController::class, 'exportJson'])->name('vat-report.export-json');
        Route::get('/vat-report/export', [VatReportController::class, 'export'])->name('vat-report.export');
        Route::get('/vat-report', [VatReportController::class, 'index'])->name('vat-report.index');

        // Wallet — money movement (cash control + ledger); distinct URL from card-type setup
        Route::get('/wallet/money-movement', [PaymentCardTypeController::class, 'moneyMovement'])->name('wallet.money-movement');

        // Payment card types (Wallet) — domain-scoped
        Route::prefix('payment-card-types')->name('payment-card-types.')->group(function () {
            Route::get('/', [PaymentCardTypeController::class, 'index'])->name('index');
            Route::get('/list', [PaymentCardTypeController::class, 'list'])->name('list');
            Route::post('/', [PaymentCardTypeController::class, 'store'])->name('store');
            Route::get('/{paymentCardType}/details', [PaymentCardTypeController::class, 'details'])->name('details');
            Route::get('/{paymentCardType}/money', [PaymentCardTypeController::class, 'money'])->name('money');
            Route::put('/{paymentCardType}', [PaymentCardTypeController::class, 'update'])->name('update');
            Route::delete('/{paymentCardType}', [PaymentCardTypeController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('wallet/cash-ledger')->name('wallet-cash-ledger.')->group(function () {
            Route::get('/', [WalletCashMovementController::class, 'index'])->name('index');
            Route::post('/', [WalletCashMovementController::class, 'store'])->name('store');
            Route::post('/opening-cash', [WalletCashMovementController::class, 'setOpeningCash'])->name('opening-cash.store');
            Route::post('/counted-cash', [WalletCashMovementController::class, 'submitCountedCash'])->name('counted-cash.store');
            Route::post('/end-shift', [WalletCashMovementController::class, 'endShift'])->name('end-shift');
            Route::post('/reopen-shift', [WalletCashMovementController::class, 'reopenShift'])->name('reopen-shift');
        });

        // Credit Management (Organization-specific)
        Route::prefix('credits')->name('credits.')->group(function () {
            Route::get('/', [CreditController::class, 'index'])->name('index');
            Route::get('/overdue', [CreditController::class, 'overdue'])->name('overdue');
            Route::get('/customers/{customer}', [CreditController::class, 'show'])->name('show');
            Route::get('/customers/{customer}/outstanding-invoices', [CreditController::class, 'outstandingInvoices'])->name('outstanding-invoices');
            Route::post('/customers/{customer}/transactions', [CreditController::class, 'storeTransaction'])->name('transactions.store');
            Route::put('/transactions/{transaction}', [CreditController::class, 'updateTransaction'])->name('transactions.update');
            Route::get('/customers/{customer}/history', [CreditController::class, 'history'])->name('history');
            Route::put('/customers/{customer}/settings', [CreditController::class, 'updateCreditSettings'])->name('settings.update');
        });

        // Users (Organization-specific)
        Route::put('/users/{user}/pin', [UserPinController::class, 'update'])
            ->name('users.pin.update');
        Route::resource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('users');

        // User Hierarchy (Organization-specific)
        Route::get('/users/hierarchy', [UserController::class, 'hierarchy'])->name('users.hierarchy');

        // Auto-assign supervisors (Organization-specific)
        Route::post('/supervisors/auto-assign', [UserController::class, 'autoAssignSupervisors'])->name('supervisors.auto-assign');

        // Available supervisors (Organization-specific)
        Route::get('/supervisors/available', [UserController::class, 'availableSupervisors'])->name('supervisors.available');
        Route::get('/supervisors/available/{user}', [UserController::class, 'availableSupervisorsForUser'])->name('supervisors.available-for-user');

        // Assign supervisor (Organization-specific)
        Route::post('/users/{user}/assign-supervisor', [UserController::class, 'assignSupervisor'])->name('users.assign-supervisor');

        // Roles removed - Roles are now global-only

        // Void Logs (Organization-specific)
        Route::get('/void-logs', [VoidLogController::class, 'index'])->name('voids.index');

        // Inventory Management (Organization-specific)
        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])->name('index');
            Route::get('/products', [InventoryController::class, 'products'])->name('products');
            Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
            Route::get('/low-stock', [InventoryController::class, 'lowStock'])->name('low-stock');
            Route::get('/valuation', [InventoryController::class, 'valuation'])->name('valuation');

            Route::post('/receive', [InventoryController::class, 'receive'])->name('receive');
            Route::post('/transfer', [InventoryController::class, 'transfer'])->name('transfer');

            // Search routes
            Route::get('/search/products', [InventoryController::class, 'searchProducts'])->name('search.products');

            Route::resource('adjustments', StockAdjustmentController::class)->names('adjustments');
            Route::post('/adjustments/{adjustment}/submit', [StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
            Route::post('/adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
            Route::post('/adjustments/{adjustment}/reject', [StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
            Route::get('/adjustment-products', [StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');

            Route::resource('locations', InventoryLocationController::class)->names('locations');
            // set-default/toggle-status handlers remain global unless you want domain-specific ones
        });

        // Terminal Setup (Organization-specific)
        Route::post('/setup-terminal', [TerminalController::class, 'setupTerminal'])->name('setup.terminal');
    });
