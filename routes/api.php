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
    InventoryLocationController,
    Api\DashboardController
};

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

// Public API routes (no authentication required)
Route::prefix('orders')->group(function () {
    Route::get('/{orderId}/view', [\App\Http\Controllers\Api\OrderViewController::class, 'show'])->name('orders.view');
    Route::get('/recent-pending', [\App\Http\Controllers\Api\OrderViewController::class, 'getRecentPending'])->name('orders.recent-pending');
});

Route::middleware('auth:sanctum')->group(function () {

    /**
     * -----------------------
     * Sales Routes
     * -----------------------
     */
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/products', [SaleController::class, 'products'])->name('products');
        Route::post('/draft', [SaleController::class, 'storeDraft'])->name('drafts.store');

        // Scoped bindings
        Route::scopeBindings()->group(function () {
            Route::post('/{sale}/sales-items/void', [SaleController::class, 'voidItem'])->name('items.void');
            Route::post('/{sale}/payments', [SaleController::class, 'proceedPayment'])->name('payment.store');
            Route::post('/{sale}/sync', [SaleController::class, 'syncDraft'])->name('sales.syncDraft');
            Route::post('/{sale}/sync-immediate', [SaleController::class, 'syncDraftImmediate'])->name('sales.syncDraftImmediate');
            Route::get('/{sale}/find-sale-item', [SaleController::class, 'findSaleItem'])->name('find-sale-item');
            Route::post('/{sale}/assign-customer', [SaleController::class, 'assignCustomer'])->name('sales.assignCustomer');
            Route::post('/{sale}/test-customer-event', [SaleController::class, 'testCustomerEvent'])->name('sales.testCustomerEvent');
            Route::post('/{sale}/process-loyalty', [SaleController::class, 'processLoyalty'])->name('sales.processLoyalty');

            // Discounts
            Route::post('/{sale}/discounts/order', [SaleDiscountController::class, 'applyOrderDiscount'])->name('discounts.order.apply');
            Route::delete('/{sale}/discounts', [SaleDiscountController::class, 'removeOrderDiscount'])->name('discounts.order.remove');
            Route::post('/{sale}/saleItems/{saleItem}/discount', [SaleDiscountController::class, 'applyItemDiscount'])->name('items.discount.apply');
            Route::delete('/{sale}/saleItems/{saleItem}/discounts/{discount}', [SaleDiscountController::class, 'removeItemDiscount'])->name('items.discount.remove');
        });
    });


    /**
     * -----------------------
     * Customer Routes
     * -----------------------
     */
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('/search', [CustomerController::class, 'search']);
        Route::get('/tier-options', [CustomerController::class, 'getTierOptions']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::get('/{customer}', [CustomerController::class, 'show']);
        Route::put('/{customer}', [CustomerController::class, 'update']);
    });


    /**
     * -----------------------
     * Loyalty Program Routes
     * -----------------------
     */
    Route::prefix('loyalty')->group(function () {
        Route::get('/stats', [LoyaltyController::class, 'stats']);
        Route::get('/customers', [LoyaltyController::class, 'customers']);
        Route::get('/analytics', [LoyaltyController::class, 'analytics']);
        Route::post('/customers/{customer}/adjust-points', [LoyaltyController::class, 'adjustPoints']);

        // Tier Management
        Route::apiResource('tiers', LoyaltyTierController::class);
    });


    /**
     * -----------------------
     * User Management Routes
     * (Super Admin / Admin / Manager)
     * -----------------------
     */
    Route::middleware(['role:super admin|admin|manager'])->group(function () {
        Route::get('/users/roles', [UserController::class, 'getRoles']);
        Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);

        Route::apiResource('users', UserController::class)->names([
            'index'   => 'api.users.index',
            'store'   => 'api.users.store',
            'show'    => 'api.users.show',
            'update'  => 'api.users.update',
            'destroy' => 'api.users.destroy',
        ]);
    });


    /**
     * -----------------------
     * Dashboard Routes
     * -----------------------
     */
    Route::prefix('dashboard')->name('dashboard.api.')->group(function () {
        Route::post('/sales-chart', [DashboardController::class, 'getSalesChartData'])->name('sales-chart');
    });


    /**
     * -----------------------
     * Inventory Routes
     * -----------------------
     */
    Route::prefix('inventory')->name('inventory.api.')->group(function () {
        // Core Inventory
        Route::get('/products', [InventoryController::class, 'products'])->name('products');
        Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
        Route::get('/low-stock', [InventoryController::class, 'lowStock'])->name('low-stock');
        Route::get('/valuation', [InventoryController::class, 'valuation'])->name('valuation');
        Route::post('/receive', [InventoryController::class, 'receive'])->name('receive');
        Route::post('/transfer', [InventoryController::class, 'transfer'])->name('transfer');

        // Search
        Route::get('/search/products', [InventoryController::class, 'searchProducts'])->name('search.products');
        Route::get('/search/movements', [InventoryController::class, 'searchMovements'])->name('search.movements');

        // Locations
        Route::get('/locations/{location}/summary', [InventoryController::class, 'getLocationSummary'])->name('locations.summary');
        Route::apiResource('locations', InventoryLocationController::class)->names([
            'index'   => 'api.locations.index',
            'store'   => 'api.locations.store',
            'show'    => 'api.locations.show',
            'update'  => 'api.locations.update',
            'destroy' => 'api.locations.destroy',
        ]);
        Route::get('/search/locations', [InventoryLocationController::class, 'search'])->name('search.locations');
        Route::post('/locations/{location}/set-default', [InventoryLocationController::class, 'setDefault'])->name('api.locations.set-default');
        Route::post('/locations/{location}/toggle-status', [InventoryLocationController::class, 'toggleStatus'])->name('api.locations.toggle-status');

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
