<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sales/products', [\App\Http\Controllers\SaleController::class, 'products'])
        ->name('sales.products');

    // draft
    Route::post('/sales/draft', [\App\Http\Controllers\SaleController::class, 'storeDraft'])
        ->name('drafts.store');

    // Routes that need scoped bindings
    Route::prefix('sales')
        ->name('sales.')
        ->scopeBindings()
        ->group(function () {
            // Void a sale item
            Route::post('/{sale}/sales-items/void', [\App\Http\Controllers\SaleController::class, 'voidItem'])
                ->name('items.void');

            // Proceed with payment
            Route::post('/{sale}/payments', [\App\Http\Controllers\SaleController::class, 'proceedPayment'])
                ->name('payment.store');

            Route::post('/{sale}/sync', [\App\Http\Controllers\SaleController::class, 'syncDraft'])
                ->name('sales.syncDraft');

            Route::post('/{sale}/sync-immediate', [\App\Http\Controllers\SaleController::class, 'syncDraftImmediate'])
                ->name('sales.syncDraftImmediate');

            // discounts
            Route::post('/{sale}/discounts/order', [\App\Http\Controllers\SaleDiscountController::class, 'applyOrderDiscount'])
                ->name('discounts.order.apply');
            Route::delete('/{sale}/discounts', [\App\Http\Controllers\SaleDiscountController::class, 'removeOrderDiscount'])
                ->name('discounts.order.remove');
            Route::post('/{sale}/saleItems/{saleItem}/discount', [\App\Http\Controllers\SaleDiscountController::class, 'applyItemDiscount'])
                ->name('items.discount.apply');

            Route::delete(
                '/{sale}/saleItems/{saleItem}/discounts/{discount}',
                [\App\Http\Controllers\SaleDiscountController::class, 'removeItemDiscount']
            )->name('items.discount.remove');

            Route::get('/{sale}/find-sale-item', [\App\Http\Controllers\SaleController::class, 'findSaleItem'])->name('find-sale-item');
            
            // Loyalty processing
            Route::post('/{sale}/process-loyalty', [\App\Http\Controllers\SaleController::class, 'processLoyalty'])
                ->name('sales.processLoyalty');
        });

    // Customer routes
    Route::prefix('customers')->group(function () {
        Route::get('/', [\App\Http\Controllers\CustomerController::class, 'index']);
        Route::get('/search', [\App\Http\Controllers\CustomerController::class, 'search']);
        Route::get('/tier-options', [\App\Http\Controllers\CustomerController::class, 'getTierOptions']);
        Route::post('/', [\App\Http\Controllers\CustomerController::class, 'store']);
        Route::get('/{customer}', [\App\Http\Controllers\CustomerController::class, 'show']);
        Route::put('/{customer}', [\App\Http\Controllers\CustomerController::class, 'update']);
    });

    // Loyalty Program API routes
    Route::prefix('loyalty')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\LoyaltyController::class, 'stats']);
        Route::get('/customers', [\App\Http\Controllers\LoyaltyController::class, 'customers']);
        Route::get('/analytics', [\App\Http\Controllers\LoyaltyController::class, 'analytics']);
        Route::post('/customers/{customer}/adjust-points', [\App\Http\Controllers\LoyaltyController::class, 'adjustPoints']);
        
        // Tier management routes
        Route::apiResource('tiers', \App\Http\Controllers\LoyaltyTierController::class);
    });

    // User Management API routes (Only for super admin, admin, and manager)
    Route::middleware(['role:super admin|admin|manager'])->group(function () {
        Route::get('/users/roles', [\App\Http\Controllers\UserController::class, 'getRoles']);
        Route::patch('/users/{user}/toggle-status', [\App\Http\Controllers\UserController::class, 'toggleStatus']);
        Route::apiResource('users', \App\Http\Controllers\UserController::class)->names([
            'index' => 'api.users.index',
            'store' => 'api.users.store',
            'show' => 'api.users.show',
            'update' => 'api.users.update',
            'destroy' => 'api.users.destroy',
        ]);
    });

    // Dashboard API routes
    Route::prefix('dashboard')->name('dashboard.api.')->group(function () {
        Route::post('/sales-chart', [\App\Http\Controllers\Api\DashboardController::class, 'getSalesChartData'])->name('sales-chart');
    });

    // Inventory API routes
    Route::prefix('inventory')->name('inventory.api.')->group(function () {
        Route::get('/products', [\App\Http\Controllers\InventoryController::class, 'products'])->name('products');
        Route::get('/movements', [\App\Http\Controllers\InventoryController::class, 'movements'])->name('movements');
        Route::get('/low-stock', [\App\Http\Controllers\InventoryController::class, 'lowStock'])->name('low-stock');
        Route::get('/valuation', [\App\Http\Controllers\InventoryController::class, 'valuation'])->name('valuation');
        Route::post('/receive', [\App\Http\Controllers\InventoryController::class, 'receive'])->name('receive');
        Route::post('/transfer', [\App\Http\Controllers\InventoryController::class, 'transfer'])->name('transfer');
        Route::get('/locations/{location}/summary', [\App\Http\Controllers\InventoryController::class, 'getLocationSummary'])->name('locations.summary');
        Route::get('/search/products', [\App\Http\Controllers\InventoryController::class, 'searchProducts'])->name('search.products');
        Route::get('/search/movements', [\App\Http\Controllers\InventoryController::class, 'searchMovements'])->name('search.movements');
        
        // Stock adjustments API
        Route::get('/adjustments', [\App\Http\Controllers\StockAdjustmentController::class, 'index'])->name('adjustments.index');
        Route::post('/adjustments', [\App\Http\Controllers\StockAdjustmentController::class, 'store'])->name('adjustments.store');
        Route::get('/adjustments/{adjustment}', [\App\Http\Controllers\StockAdjustmentController::class, 'show'])->name('adjustments.show');
        Route::put('/adjustments/{adjustment}', [\App\Http\Controllers\StockAdjustmentController::class, 'update'])->name('adjustments.update');
        Route::post('/adjustments/{adjustment}/submit', [\App\Http\Controllers\StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
        Route::post('/adjustments/{adjustment}/approve', [\App\Http\Controllers\StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/reject', [\App\Http\Controllers\StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
        Route::get('/adjustment-products', [\App\Http\Controllers\StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');
        
        // Location Management API
        Route::apiResource('locations', \App\Http\Controllers\InventoryLocationController::class)->names([
            'index' => 'api.locations.index',
            'store' => 'api.locations.store',
            'show' => 'api.locations.show',
            'update' => 'api.locations.update',
            'destroy' => 'api.locations.destroy',
        ]);
        Route::get('/search/locations', [\App\Http\Controllers\InventoryLocationController::class, 'search'])->name('search.locations');
        Route::post('/locations/{location}/set-default', [\App\Http\Controllers\InventoryLocationController::class, 'setDefault'])->name('api.locations.set-default');
        Route::post('/locations/{location}/toggle-status', [\App\Http\Controllers\InventoryLocationController::class, 'toggleStatus'])->name('api.locations.toggle-status');
    });

});
