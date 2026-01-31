<?php

use Illuminate\Support\Facades\Route;

Route::prefix('domains/{domain:name_slug}')
    ->middleware(['auth', 'user.permission', 'role.access'])
    ->name('domains.')
    ->group(function () {
        // Dashboard (Organization-specific)
        Route::get('/dashboard', [\App\Http\Controllers\Domains\DashboardController::class, 'index'])->name('dashboard');

        // Dashboard API routes (Organization-specific)
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::post('/sales-chart', [\App\Http\Controllers\Domains\DashboardController::class, 'getSalesChartData'])->name('sales-chart');
        });

        // Sales (Organization-specific)
        Route::get('/sales', [\App\Http\Controllers\Domains\SaleController::class, 'index'])->name('sales.index');
        Route::get('/sales/products', [\App\Http\Controllers\Domains\SaleController::class, 'products'])->name('sales.products');

        // User-specific sales routes (handles "no sales id yet" case)
        Route::prefix('users/{user}')->name('users.')->group(function () {
            // Create new sale for user (when no sales id yet)
            Route::post('/sales', [\App\Http\Controllers\Domains\SaleController::class, 'createSaleForUser'])->name('sales.create');

            // Add item to user's latest pending sale (auto-finds or creates)
            Route::post('/sales/cart/add', [\App\Http\Controllers\Domains\SaleController::class, 'addItemToUserCart'])->name('sales.cart.add');

            // Get user's current pending sale
            Route::get('/sales/current-pending', [\App\Http\Controllers\Domains\SaleController::class, 'getUserPendingSale'])->name('sales.current-pending');

            // Other cart operations for user's latest sale
            Route::patch('/sales/cart/update-quantity', [\App\Http\Controllers\Domains\SaleController::class, 'updateUserCartQuantity'])->name('sales.cart.update-quantity');
            Route::delete('/sales/cart/remove', [\App\Http\Controllers\Domains\SaleController::class, 'removeFromUserCart'])->name('sales.cart.remove');
            Route::get('/sales/cart/state', [\App\Http\Controllers\Domains\SaleController::class, 'getUserCartState'])->name('sales.cart.state');
        });

        // Sales API routes (Organization-specific)
        Route::prefix('sales')->name('sales.')->group(function () {
            Route::post('/draft', [\App\Http\Controllers\Domains\SaleController::class, 'storeDraft'])->name('drafts.store');
            Route::get('/oversell-statistics', [\App\Http\Controllers\Domains\SaleController::class, 'getOversellStatistics'])->name('oversell.statistics');

            // Scoped bindings
            Route::scopeBindings()->group(function () {
                Route::post('/{sale}/sales-items/void', [\App\Http\Controllers\Domains\SaleController::class, 'voidItem'])->name('items.void');
                Route::post('/{sale}/payments', [\App\Http\Controllers\Domains\SaleController::class, 'proceedPayment'])->name('payment.store');
                // Cart management - database-driven
                Route::post('/{sale}/cart/add', [\App\Http\Controllers\Domains\SaleController::class, 'addItemToCart'])->name('cart.add');
                Route::delete('/{sale}/cart/remove', [\App\Http\Controllers\Domains\SaleController::class, 'removeItemFromCart'])->name('cart.remove');
                Route::patch('/{sale}/cart/update-quantity', [\App\Http\Controllers\Domains\SaleController::class, 'updateItemQuantity'])->name('cart.update-quantity');
                Route::get('/{sale}/cart/state', [\App\Http\Controllers\Domains\SaleController::class, 'getCartState'])->name('cart.state');

                // Discounts - database-driven
                Route::get('/{sale}/discounts', [\App\Http\Controllers\Domains\SaleDiscountController::class, 'getSaleDiscounts'])->name('discounts.sale');
                Route::patch('/{sale}/discounts', [\App\Http\Controllers\Domains\SaleDiscountController::class, 'updateSaleDiscounts'])->name('discounts.update');
                Route::delete('/{sale}/discounts', [\App\Http\Controllers\Domains\SaleDiscountController::class, 'removeSaleDiscounts'])->name('discounts.remove');

                // Item-level discounts
                Route::post('/{sale}/items/{saleItem}/discounts', [\App\Http\Controllers\Domains\SaleDiscountController::class, 'applyItemDiscount'])->name('items.discount.apply');
                Route::delete('/{sale}/items/{saleItem}/discounts', [\App\Http\Controllers\Domains\SaleDiscountController::class, 'removeItemDiscount'])->name('items.discount.remove');
                Route::get('/{sale}/find-sale-item', [\App\Http\Controllers\Domains\SaleController::class, 'findSaleItem'])->name('find-sale-item');
                Route::post('/{sale}/assign-customer', [\App\Http\Controllers\Domains\SaleController::class, 'assignCustomer'])->name('sales.assignCustomer');
                Route::post('/{sale}/process-loyalty', [\App\Http\Controllers\Domains\SaleController::class, 'processLoyalty'])->name('sales.processLoyalty');
                Route::post('/{sale}/test-order-event', [\App\Http\Controllers\Domains\SaleController::class, 'testOrderEvent'])->name('sales.testOrderEvent');
            });
        });

        // Products (Organization-specific)
        Route::resource('products', \App\Http\Controllers\Domains\ProductController::class)
            ->only(['index', 'store', 'update', 'destroy', 'create', 'edit'])
            ->names('products');

        // Categories (Organization-specific)
        Route::resource('categories', \App\Http\Controllers\Domains\CategoryController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('categories');

        // Product Discounts (Organization-specific)
        Route::name('products.')->group(function () {
            Route::resource('discounts', \App\Http\Controllers\Products\DiscountController::class)
                ->only(['index', 'store', 'update', 'destroy'])
                ->names('discounts');
        });

        // Mandatory Discounts (Organization-specific)
        Route::resource('mandatory-discounts', \App\Http\Controllers\MandatoryDiscountController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('mandatory-discounts');

        // Loyalty Program (Organization-specific)
        Route::get('/loyalty', [\App\Http\Controllers\Domains\LoyaltyController::class, 'index'])->name('loyalty.index');
        Route::get('/loyalty/stats', [\App\Http\Controllers\Domains\LoyaltyController::class, 'stats'])->name('loyalty.stats');
        Route::get('/loyalty/customers', [\App\Http\Controllers\Domains\LoyaltyController::class, 'customers'])->name('loyalty.customers');
        Route::get('/loyalty/analytics', [\App\Http\Controllers\Domains\LoyaltyController::class, 'analytics'])->name('loyalty.analytics');
        Route::post('/loyalty/customers/{customer}/adjust-points', [\App\Http\Controllers\Domains\LoyaltyController::class, 'adjustPoints'])->name('loyalty.adjust-points');

        // Tier Management (Organization-specific)
        Route::apiResource('loyalty/tiers', \App\Http\Controllers\Domains\LoyaltyTierController::class)->names([
            'index'   => 'loyalty.tiers.index',
            'store'   => 'loyalty.tiers.store',
            'show'    => 'loyalty.tiers.show',
            'update'  => 'loyalty.tiers.update',
            'destroy' => 'loyalty.tiers.destroy',
        ]);

        // Customers (Organization-specific)
        Route::resource('customers', \App\Http\Controllers\Domains\CustomerController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('customers');

        // Credit Management (Organization-specific)
        Route::prefix('credits')->name('credits.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Domains\CreditController::class, 'index'])->name('index');
            Route::get('/overdue', [\App\Http\Controllers\Domains\CreditController::class, 'overdue'])->name('overdue');
            Route::get('/customers/{customer}', [\App\Http\Controllers\Domains\CreditController::class, 'show'])->name('show');
            Route::post('/customers/{customer}/transactions', [\App\Http\Controllers\Domains\CreditController::class, 'storeTransaction'])->name('transactions.store');
            Route::put('/transactions/{transaction}', [\App\Http\Controllers\Domains\CreditController::class, 'updateTransaction'])->name('transactions.update');
            Route::get('/customers/{customer}/history', [\App\Http\Controllers\Domains\CreditController::class, 'history'])->name('history');
            Route::put('/customers/{customer}/settings', [\App\Http\Controllers\Domains\CreditController::class, 'updateCreditSettings'])->name('settings.update');
        });

        // Users (Organization-specific)
        Route::resource('users', \App\Http\Controllers\Domains\UserController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('users');

        // User Hierarchy (Organization-specific)
        Route::get('/users/hierarchy', [\App\Http\Controllers\Domains\UserController::class, 'hierarchy'])->name('users.hierarchy');

        // Auto-assign supervisors (Organization-specific)
        Route::post('/supervisors/auto-assign', [\App\Http\Controllers\Domains\UserController::class, 'autoAssignSupervisors'])->name('supervisors.auto-assign');

        // Available supervisors (Organization-specific)
        Route::get('/supervisors/available', [\App\Http\Controllers\Domains\UserController::class, 'availableSupervisors'])->name('supervisors.available');
        Route::get('/supervisors/available/{user}', [\App\Http\Controllers\Domains\UserController::class, 'availableSupervisorsForUser'])->name('supervisors.available-for-user');

        // Assign supervisor (Organization-specific)
        Route::post('/users/{user}/assign-supervisor', [\App\Http\Controllers\Domains\UserController::class, 'assignSupervisor'])->name('users.assign-supervisor');

        // Roles removed - Roles are now global-only

        // Void Logs (Organization-specific)
        Route::get('/void-logs', [\App\Http\Controllers\VoidLogController::class, 'index'])->name('voids.index');

        // Inventory Management (Organization-specific)
        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'index'])->name('index');
            Route::get('/products', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'products'])->name('products');
            Route::get('/movements', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'movements'])->name('movements');
            Route::get('/low-stock', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'lowStock'])->name('low-stock');
            Route::get('/valuation', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'valuation'])->name('valuation');

            Route::post('/receive', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'receive'])->name('receive');
            Route::post('/transfer', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'transfer'])->name('transfer');

            // Search routes
            Route::get('/search/products', [\App\Http\Controllers\Domains\Inventory\InventoryController::class, 'searchProducts'])->name('search.products');

            Route::resource('adjustments', \App\Http\Controllers\Domains\Inventory\StockAdjustmentController::class)->names('adjustments');
            Route::post('/adjustments/{adjustment}/submit', [\App\Http\Controllers\Domains\Inventory\StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
            Route::post('/adjustments/{adjustment}/approve', [\App\Http\Controllers\Domains\Inventory\StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
            Route::post('/adjustments/{adjustment}/reject', [\App\Http\Controllers\Domains\Inventory\StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
            Route::get('/adjustment-products', [\App\Http\Controllers\Domains\Inventory\StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');

            Route::resource('locations', \App\Http\Controllers\Domains\Inventory\InventoryLocationController::class)->names('locations');
            // set-default/toggle-status handlers remain global unless you want domain-specific ones
        });

        // Terminal Setup (Organization-specific)
        Route::post('/setup-terminal', [\App\Http\Controllers\TerminalController::class, 'setupTerminal'])->name('setup.terminal');
    });
