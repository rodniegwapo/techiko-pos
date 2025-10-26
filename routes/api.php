<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    SaleController,
    SaleDiscountController,
    CustomerController,
    LoyaltyController,
    LoyaltyTierController,
    UserController,
    InventoryController,
    StockAdjustmentController,
    InventoryLocationController
};

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
})->name('user');

/**
 * -----------------------
 * Public API routes (no authentication required)
 * -----------------------
 */
Route::prefix('orders')->group(function () {
    Route::get('/{orderId}/view', [\App\Http\Controllers\Api\OrderViewController::class, 'show'])->name('orders.view');
    Route::get('/recent-pending', [\App\Http\Controllers\Api\OrderViewController::class, 'getRecentPending'])->name('orders.recent-pending');
});

Route::middleware(['auth:sanctum', 'user.permission'])->group(function () {

    /**
     * -----------------------
     * Global API Routes (non-domain specific)
     * -----------------------
     */
    // Global Dashboard API routes
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::post('/sales-chart', [\App\Http\Controllers\DashboardController::class, 'getSalesChartData'])->name('sales-chart');
    });
    
    // Note: Sales routes are now domain-specific and handled in routes/domains.php


    /**
     * -----------------------
     * Customer Routes
     * -----------------------
     */
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/search', [CustomerController::class, 'search'])->name('customers.search');
        Route::get('/tier-options', [CustomerController::class, 'getTierOptions'])->name('customers.tier-options');
        Route::post('/', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::put('/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    });


    /**
     * -----------------------
     * Loyalty Program Routes
     * -----------------------
     */
    Route::prefix('loyalty')->group(function () {
        Route::get('/stats', [LoyaltyController::class, 'stats'])->name('loyalty.stats');
        Route::get('/customers', [LoyaltyController::class, 'customers'])->name('loyalty.customers');
        Route::get('/analytics', [LoyaltyController::class, 'analytics'])->name('loyalty.analytics');
        Route::post('/customers/{customer}/adjust-points', [LoyaltyController::class, 'adjustPoints'])->name('loyalty.adjust-points');

        // Tier Management
        Route::apiResource('tiers', LoyaltyTierController::class)->names([
            'index'   => 'loyalty.tiers.index',
            'store'   => 'loyalty.tiers.store',
            'show'    => 'loyalty.tiers.show',
            'update'  => 'loyalty.tiers.update',
            'destroy' => 'loyalty.tiers.destroy',
        ]);
    });


    /**
     * -----------------------
     * User Management Routes
     * -----------------------
     */
    Route::get('/users/roles', [UserController::class, 'getRoles'])->name('users.roles');
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');

    Route::apiResource('users', UserController::class)->names([
        'index'   => 'users.index',
        'store'   => 'users.store',
        'show'    => 'users.show',
        'update'  => 'users.update',
        'destroy' => 'users.destroy',
    ]);




    /**
     * -----------------------
     * Inventory Routes
     * -----------------------
     */
    Route::prefix('inventory')->name('inventory.')->group(function () {
        // Core Inventory
        Route::get('/products', [InventoryController::class, 'products'])->name('products');
        Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
        Route::get('/low-stock', [InventoryController::class, 'lowStock'])->name('low-stock');
        Route::get('/valuation', [InventoryController::class, 'valuation'])->name('valuation');

        // Inventory Operations
        Route::post('/receive', [InventoryController::class, 'receive'])->name('receive');
        Route::post('/transfer', [InventoryController::class, 'transfer'])->name('transfer');

        // Search
        Route::get('/search/products', [InventoryController::class, 'searchProducts'])->name('search.products');
        Route::get('/search/movements', [InventoryController::class, 'searchMovements'])->name('search.movements');

        // Locations
        Route::get('/locations/by-domain', [InventoryController::class, 'getLocationsByDomain'])->name('locations.by-domain');
        Route::get('/locations/{location}/summary', [InventoryController::class, 'getLocationSummary'])->name('locations.summary');
        Route::apiResource('locations', InventoryLocationController::class)->names([
            'index'   => 'locations.index',
            'store'   => 'locations.store',
            'show'    => 'locations.show',
            'update'  => 'locations.update',
            'destroy' => 'locations.destroy',
        ]);
        Route::get('/search/locations', [InventoryLocationController::class, 'search'])->name('search.locations');
        Route::post('/locations/{location}/set-default', [InventoryLocationController::class, 'setDefault'])->name('locations.set-default');
        Route::post('/locations/{location}/toggle-status', [InventoryLocationController::class, 'toggleStatus'])->name('locations.toggle-status');

        // Stock Adjustments
        Route::get('/adjustments', [StockAdjustmentController::class, 'index'])->name('adjustments.index');
        Route::post('/adjustments', [StockAdjustmentController::class, 'store'])->name('adjustments.store');
        Route::get('/adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])->name('adjustments.show');
        Route::put('/adjustments/{adjustment}', [StockAdjustmentController::class, 'update'])->name('adjustments.update');
        Route::post('/adjustments/{adjustment}/submit', [StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
        Route::post('/adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/reject', [StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
        Route::get('/adjustment-products', [StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');
    });
});
