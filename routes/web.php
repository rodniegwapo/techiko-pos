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
*/

Route::get('/', function () {
    return Inertia::render('Auth/Login');
})->name('login');

Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'user.permission'])->group(function () {
    // Categories Management
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('categories');

    // Products Management
    Route::resource('products', \App\Http\Controllers\Products\ProductController::class)
        ->only(['index', 'store', 'update', 'destroy',])
        ->names('products');

    Route::name('products.')->group(function () {
        Route::resource('discounts', \App\Http\Controllers\Products\DiscountController::class)
            ->only(['index', 'store', 'update', 'destroy',])
            ->names('discounts');
    });

    // Mandatory Discounts
    Route::resource('mandatory-discounts', \App\Http\Controllers\MandatoryDiscountController::class)
        ->only(['index', 'store', 'update', 'destroy',])
        ->names('mandatory-discounts');

    // Sales
    Route::get('/sales', [\App\Http\Controllers\SaleController::class, 'index'])->name('sales.index');

    // Loyalty Program
    Route::get('/loyalty', [\App\Http\Controllers\LoyaltyController::class, 'index'])->name('loyalty.index');

    // Customers
    Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'webIndex'])->name('customers.index');

    // User Management
    Route::get('/users', [\App\Http\Controllers\UserController::class, 'index'])->name('users.index');
    Route::get('/users/hierarchy', [\App\Http\Controllers\UserController::class, 'hierarchy'])->name('users.hierarchy');
    Route::get('/users/{user}', [\App\Http\Controllers\UserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/edit', [\App\Http\Controllers\UserController::class, 'edit'])->name('users.edit');


    // Supervisor Assignment Routes (Level-based)
    Route::post('/users/{user}/assign-supervisor', [\App\Http\Controllers\SupervisorAssignmentController::class, 'assign'])
        ->name('users.assign-supervisor');
    Route::delete('/users/{user}/remove-supervisor', [\App\Http\Controllers\SupervisorAssignmentController::class, 'remove'])
        ->name('users.remove-supervisor');
    Route::get('/supervisors/available', [\App\Http\Controllers\SupervisorAssignmentController::class, 'availableSupervisors'])
        ->name('supervisors.available');
    Route::get('/supervisors/available/{user}', [\App\Http\Controllers\SupervisorAssignmentController::class, 'availableSupervisors'])
        ->name('supervisors.available-for-user');
    Route::post('/supervisors/auto-assign', [\App\Http\Controllers\SupervisorAssignmentController::class, 'autoAssign'])
        ->name('supervisors.auto-assign');
    Route::get('/users/{user}/supervisor-history', [\App\Http\Controllers\SupervisorAssignmentController::class, 'history'])
        ->name('users.supervisor-history');

    // Cascading Assignment Routes
    Route::get('/supervisors/cascading-options', [\App\Http\Controllers\SupervisorAssignmentController::class, 'cascadingOptions'])
        ->name('supervisors.cascading-options');
    Route::post('/supervisors/{supervisor}/cascading-assign', [\App\Http\Controllers\SupervisorAssignmentController::class, 'cascadingAssign'])
        ->name('supervisors.cascading-assign');

    // Role Management (Only for super user)
    Route::middleware(['auth', 'user.permission'])->group(function () {
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

    // Permission Management (Only for super user)
    Route::middleware(['auth', 'check.super.user'])->group(function () {
        Route::get('/permissions', [\App\Http\Controllers\PermissionController::class, 'index'])->name('permissions.index');
        Route::post('/permissions', [\App\Http\Controllers\PermissionController::class, 'store'])->name('permissions.store');
        Route::get('/permissions/{permission}', [\App\Http\Controllers\PermissionController::class, 'show'])->name('permissions.show');
        Route::put('/permissions/{permission}', [\App\Http\Controllers\PermissionController::class, 'update'])->name('permissions.update');
        Route::delete('/permissions/{permission}', [\App\Http\Controllers\PermissionController::class, 'destroy'])->name('permissions.destroy');
        Route::post('/permissions/{permission}/deactivate', [\App\Http\Controllers\PermissionController::class, 'deactivate'])->name('permissions.deactivate');
        Route::post('/permissions/{permission}/activate', [\App\Http\Controllers\PermissionController::class, 'activate'])->name('permissions.activate');
        Route::post('/permissions/bulk-deactivate', [\App\Http\Controllers\PermissionController::class, 'bulkDeactivate'])->name('permissions.bulk-deactivate');
        Route::get('/permissions-grouped', [\App\Http\Controllers\PermissionController::class, 'getGroupedPermissions'])->name('permissions.grouped');
    });

    // Terminal Setup
    Route::post('/setup-terminal', [\App\Http\Controllers\TerminalController::class, 'setupTerminal'])->name('setup.terminal');

    // Void Logs
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
        Route::post('/adjustments/{adjustment}/submit', [\App\Http\Controllers\StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
        Route::post('/adjustments/{adjustment}/approve', [\App\Http\Controllers\StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/reject', [\App\Http\Controllers\StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
        Route::get('/adjustment-products', [\App\Http\Controllers\StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');

        // Location Management
        Route::resource('locations', \App\Http\Controllers\InventoryLocationController::class)->names('locations');
        Route::post('/locations/{location}/set-default', [\App\Http\Controllers\InventoryLocationController::class, 'setDefault'])->name('locations.set-default');
        Route::post('/locations/{location}/toggle-status', [\App\Http\Controllers\InventoryLocationController::class, 'toggleStatus'])->name('locations.toggle-status');
    });
});

Route::get('/customer-order', function () {
    return Inertia::render('CustomerOrderView');
})->name('customer-order');

require __DIR__ . '/auth.php';
