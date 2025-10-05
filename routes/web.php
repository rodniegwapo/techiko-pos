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

Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth'])->group(function () {
    // Categories Management (Admin, Manager, Supervisor)
    Route::middleware(['check.permission:categories.view'])->group(function () {
        Route::resource('categories', \App\Http\Controllers\CategoryController::class)->names('categories');
    });

    // Products Management (Admin, Manager, Supervisor)
    Route::middleware(['check.permission:products.view'])->group(function () {
        Route::resource('products', \App\Http\Controllers\Products\ProductController::class)->names('products');
        Route::name('products.')->group(function () {
            Route::resource('discounts', \App\Http\Controllers\Products\DiscountController::class)
                ->names('discounts');
        });
    });

    // Mandatory Discounts (Admin, Manager)
    Route::middleware(['check.permission:discounts.manage'])->group(function () {
        Route::resource('mandatory-discounts', \App\Http\Controllers\MandatoryDiscountController::class)->names('mandatory-discounts');
    });

    // Sales (All roles)
    Route::middleware(['check.permission:sales.view'])->group(function () {
        Route::get('/sales', [\App\Http\Controllers\SaleController::class, 'index'])->name('sales.index');
    });

    // Loyalty Program (Admin, Manager, Supervisor)
    Route::middleware(['check.permission:loyalty.view'])->group(function () {
        Route::get('/loyalty', [\App\Http\Controllers\LoyaltyController::class, 'index'])->name('loyalty.index');
    });

    // Customers (Admin, Manager, Supervisor)
    Route::middleware(['check.permission:customers.view'])->group(function () {
        Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'webIndex'])->name('customers.index');
    });

    // User Management (Super user, admin, and manager)
    Route::middleware(['check.permission:users.view'])->group(function () {
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

    // Role Management (Only for super user)
    Route::middleware(['check.super.user'])->group(function () {
        Route::get('/roles', [\App\Http\Controllers\RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [\App\Http\Controllers\RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [\App\Http\Controllers\RoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'show'])->name('roles.show');
        Route::get('/roles/{role}/edit', [\App\Http\Controllers\RoleController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'destroy'])->name('roles.destroy');
        Route::get('/roles-permissions/matrix', [\App\Http\Controllers\RoleController::class, 'permissionMatrix'])->name('roles.permission-matrix');
        Route::get('/roles-permissions/permissions', [\App\Http\Controllers\RoleController::class, 'permissions'])->name('roles.permissions');
    });

    // Terminal Setup (Admin, Manager)
    Route::middleware(['check.permission:terminal.manage'])->group(function () {
        Route::post('/setup-terminal', [\App\Http\Controllers\TerminalController::class, 'setupTerminal'])->name('setup.terminal');
    });

    // Void Logs (Admin, Manager, Supervisor)
    Route::middleware(['check.permission:voids.view'])->group(function () {
        Route::get('/void-logs', [\App\Http\Controllers\VoidLogController::class, 'index'])->name('voids.index');
    });

    // Inventory Management Routes (Admin, Manager)
    Route::middleware(['check.permission:inventory.view'])->group(function () {
        Route::prefix('inventory')->name('inventory.')->group(function () {
            // Inventory Overview
            Route::get('/', [\App\Http\Controllers\InventoryController::class, 'index'])->name('index');
            Route::get('/products', [\App\Http\Controllers\InventoryController::class, 'products'])->name('products');
            Route::get('/movements', [\App\Http\Controllers\InventoryController::class, 'movements'])->name('movements');
            Route::get('/low-stock', [\App\Http\Controllers\InventoryController::class, 'lowStock'])->name('low-stock');
            Route::get('/valuation', [\App\Http\Controllers\InventoryController::class, 'valuation'])->name('valuation');

            // Inventory Operations (Admin, Manager)
            Route::middleware(['check.permission:inventory.receive'])->group(function () {
                Route::post('/receive', [\App\Http\Controllers\InventoryController::class, 'receive'])->name('receive');
            });
            Route::middleware(['check.permission:inventory.transfer'])->group(function () {
                Route::post('/transfer', [\App\Http\Controllers\InventoryController::class, 'transfer'])->name('transfer');
            });

            // Stock Adjustments (Admin, Manager)
            Route::middleware(['check.permission:inventory.adjustments'])->group(function () {
                Route::resource('adjustments', \App\Http\Controllers\StockAdjustmentController::class)->names('adjustments');
                Route::post('/adjustments/{adjustment}/submit', [\App\Http\Controllers\StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
                Route::post('/adjustments/{adjustment}/approve', [\App\Http\Controllers\StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
                Route::post('/adjustments/{adjustment}/reject', [\App\Http\Controllers\StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
                Route::get('/adjustment-products', [\App\Http\Controllers\StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');
            });

            // Location Management (Admin, Manager)
            Route::middleware(['check.permission:inventory.locations'])->group(function () {
                Route::resource('locations', \App\Http\Controllers\InventoryLocationController::class)->names('locations');
                Route::post('/locations/{location}/set-default', [\App\Http\Controllers\InventoryLocationController::class, 'setDefault'])->name('locations.set-default');
                Route::post('/locations/{location}/toggle-status', [\App\Http\Controllers\InventoryLocationController::class, 'toggleStatus'])->name('locations.toggle-status');
            });
        });
    });
});

Route::get('/customer-order', function () {
    return Inertia::render('CustomerOrderView');
})->name('customer-order');


require __DIR__ . '/auth.php';
