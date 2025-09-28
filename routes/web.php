<?php

use App\Events\OrderUpdated;
use App\Http\Controllers\ProfileController;
use App\Models\Sale;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Auth/Login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Route::middleware('auth')->group(function () {
//     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
//     Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
//     Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
// });

Route::middleware(['auth'])->group(function () {
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)->names('categories');
    Route::resource('products', \App\Http\Controllers\Products\ProductController::class)->names('products');
    Route::name('products.')->group(function () {
        Route::resource('discounts', \App\Http\Controllers\Products\DiscountController::class)
            ->names('discounts');
    });

    // Mandatory Discounts
    Route::resource('mandatory-discounts', \App\Http\Controllers\MandatoryDiscountController::class)->names('mandatory-discounts');

    Route::get('/sales', [\App\Http\Controllers\SaleController::class, 'index'])->name('sales.index');
    
    // Loyalty Program
    Route::get('/loyalty', [\App\Http\Controllers\LoyaltyController::class, 'index'])->name('loyalty.index');
    
    // Customers
    Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'webIndex'])->name('customers.index');
    
    // User Management (Only for super admin, admin, and manager)
    Route::middleware(['role:super admin|admin|manager'])->group(function () {
        Route::get('/users', [\App\Http\Controllers\UserController::class, 'index'])->name('users.index');
        
        // Supervisor Assignment Routes
        Route::post('/users/{user}/assign-supervisor', [\App\Http\Controllers\SupervisorAssignmentController::class, 'assign'])
            ->name('users.assign-supervisor');
        Route::delete('/users/{user}/remove-supervisor', [\App\Http\Controllers\SupervisorAssignmentController::class, 'remove'])
            ->name('users.remove-supervisor');
        Route::get('/supervisors/available', [\App\Http\Controllers\SupervisorAssignmentController::class, 'availableSupervisors'])
            ->name('supervisors.available');
        Route::get('/users/{user}/supervisor-history', [\App\Http\Controllers\SupervisorAssignmentController::class, 'history'])
            ->name('users.supervisor-history');
    });
    
    // terminal
    Route::post('/setup-terminal', [\App\Http\Controllers\TerminalController::class, 'setupTerminal'])->name('setup.terminal');

    // Void logs
    Route::get('/void-logs', [\App\Http\Controllers\VoidLogController::class, 'index'])->name('voids.index');
    
    // Inventory Management Routes
    Route::prefix('inventory')->name('inventory.')->group(function () {
        // Inventory Overview
        Route::get('/', [\App\Http\Controllers\InventoryController::class, 'index'])->name('index');
        Route::get('/products', [\App\Http\Controllers\InventoryController::class, 'products'])->name('products');
        Route::get('/movements', [\App\Http\Controllers\InventoryController::class, 'movements'])->name('movements');
        Route::get('/low-stock', [\App\Http\Controllers\InventoryController::class, 'lowStock'])->name('low-stock');
        Route::get('/valuation', [\App\Http\Controllers\InventoryController::class, 'valuation'])->name('valuation');
        
        // Inventory Operations
        Route::post('/receive', [\App\Http\Controllers\InventoryController::class, 'receive'])->name('receive');
        Route::post('/transfer', [\App\Http\Controllers\InventoryController::class, 'transfer'])->name('transfer');
        
        // Stock Adjustments
        Route::resource('adjustments', \App\Http\Controllers\StockAdjustmentController::class)->names('adjustments');
        Route::post('/adjustments/{stockAdjustment}/submit', [\App\Http\Controllers\StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
        Route::post('/adjustments/{stockAdjustment}/approve', [\App\Http\Controllers\StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('/adjustments/{stockAdjustment}/reject', [\App\Http\Controllers\StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
        Route::get('/adjustment-products', [\App\Http\Controllers\StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');
    });
});

Route::get('/test-order', function () {
    $sales = Sale::all();

    if ($sales->isNotEmpty()) {
        // Trigger broadcast for each sale (if you want all sales individually)
        foreach ($sales as $sale) {
            event(new OrderUpdated($sale));
        }

        return Inertia::render('Sales/components/CustomerOrderView');
    }

    return "No orders found";
});
require __DIR__.'/auth.php';
