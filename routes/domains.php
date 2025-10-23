<?php

use Illuminate\Support\Facades\Route;

Route::prefix('domains/{domain:name_slug}')
    ->middleware(['auth', 'user.permission'])
    ->name('domains.')
    ->group(function () {
        // Dashboard (Organization-specific)
        Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

        // Sales (Organization-specific)
        Route::get('/sales', [\App\Http\Controllers\Domains\SaleController::class, 'index'])->name('sales.index');
        Route::get('/sales/products', [\App\Http\Controllers\Domains\SaleController::class, 'products'])->name('sales.products');

        // Products (Organization-specific)
        Route::resource('products', \App\Http\Controllers\Domains\ProductController::class)
            ->only(['index', 'store', 'update', 'destroy'])
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

        // Users (Organization-specific)
        Route::resource('users', \App\Http\Controllers\Domains\UserController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('users');
        
        // User Hierarchy (Organization-specific)
        Route::get('/users/hierarchy', [\App\Http\Controllers\Domains\UserController::class, 'hierarchy'])->name('users.hierarchy');
        
        // Auto-assign supervisors (Organization-specific)
        Route::post('/supervisors/auto-assign', [\App\Http\Controllers\Domains\UserController::class, 'autoAssignSupervisors'])->name('supervisors.auto-assign');

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


