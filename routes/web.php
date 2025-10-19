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

// ===================================
// GLOBAL ROUTES (Super Users Only)
// ===================================
Route::middleware(['auth', 'user.permission'])->group(function () {
    // Sales (Global - All Organizations)
    Route::get('/sales', [\App\Http\Controllers\SaleController::class, 'index'])->name('sales.index');
    
    // Products (Global - All Organizations)
    Route::resource('products', \App\Http\Controllers\Products\ProductController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('products');
    
    // Categories (Global - All Organizations)
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('categories');
    
    // Product Discounts (Global)
    Route::name('products.')->group(function () {
        Route::resource('discounts', \App\Http\Controllers\Products\DiscountController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('discounts');
    });
    
    // Mandatory Discounts (Global)
    Route::resource('mandatory-discounts', \App\Http\Controllers\MandatoryDiscountController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('mandatory-discounts');
    
    // Loyalty Program (Global)
    Route::get('/loyalty', [\App\Http\Controllers\LoyaltyController::class, 'index'])->name('loyalty.index');
    
    // Customers (Global)
    Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'webIndex'])->name('customers.index');
    
    // Void Logs (Global)
    Route::get('/void-logs', [\App\Http\Controllers\VoidLogController::class, 'index'])->name('voids.index');
    
    // Inventory Management (Global)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [\App\Http\Controllers\InventoryController::class, 'index'])->name('index');
        Route::get('/products', [\App\Http\Controllers\InventoryController::class, 'products'])->name('products');
        Route::get('/movements', [\App\Http\Controllers\InventoryController::class, 'movements'])->name('movements');
        Route::get('/low-stock', [\App\Http\Controllers\InventoryController::class, 'lowStock'])->name('low-stock');
        Route::get('/valuation', [\App\Http\Controllers\InventoryController::class, 'valuation'])->name('valuation');
        
        Route::post('/receive', [\App\Http\Controllers\InventoryController::class, 'receive'])->name('receive');
        Route::post('/transfer', [\App\Http\Controllers\InventoryController::class, 'transfer'])->name('transfer');
        
        Route::resource('adjustments', \App\Http\Controllers\StockAdjustmentController::class)->names('adjustments');
        Route::post('/adjustments/{adjustment}/submit', [\App\Http\Controllers\StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
        Route::post('/adjustments/{adjustment}/approve', [\App\Http\Controllers\StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/reject', [\App\Http\Controllers\StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
        Route::get('/adjustment-products', [\App\Http\Controllers\StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');
        
        Route::resource('locations', \App\Http\Controllers\InventoryLocationController::class)->names('locations');
        Route::post('/locations/{location}/set-default', [\App\Http\Controllers\InventoryLocationController::class, 'setDefault'])->name('locations.set-default');
        Route::post('/locations/{location}/toggle-status', [\App\Http\Controllers\InventoryLocationController::class, 'toggleStatus'])->name('locations.toggle-status');
    });
});

// ===================================
// ORGANIZATION-SPECIFIC ROUTES
// ===================================
Route::prefix('domains/{domain:name_slug}')->middleware(['auth', 'user.permission'])->name('domains.')->group(function () {
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
    Route::get('/loyalty', [\App\Http\Controllers\LoyaltyController::class, 'index'])->name('loyalty.index');
    
    // Customers (Organization-specific)
    Route::resource('customers', \App\Http\Controllers\Domains\CustomerController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('customers');
    
    // Users (Organization-specific)
    Route::resource('users', \App\Http\Controllers\Domains\UserController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('users');
    
    // Roles (Organization-specific)
    Route::resource('roles', \App\Http\Controllers\Domains\RoleController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('roles');
    
    // Void Logs (Organization-specific)
    Route::get('/void-logs', [\App\Http\Controllers\VoidLogController::class, 'index'])->name('voids.index');
    
    // Inventory Management (Organization-specific)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [\App\Http\Controllers\InventoryController::class, 'index'])->name('index');
        Route::get('/products', [\App\Http\Controllers\InventoryController::class, 'products'])->name('products');
        Route::get('/movements', [\App\Http\Controllers\InventoryController::class, 'movements'])->name('movements');
        Route::get('/low-stock', [\App\Http\Controllers\InventoryController::class, 'lowStock'])->name('low-stock');
        Route::get('/valuation', [\App\Http\Controllers\InventoryController::class, 'valuation'])->name('valuation');
        
        Route::post('/receive', [\App\Http\Controllers\InventoryController::class, 'receive'])->name('receive');
        Route::post('/transfer', [\App\Http\Controllers\InventoryController::class, 'transfer'])->name('transfer');
        
        Route::resource('adjustments', \App\Http\Controllers\StockAdjustmentController::class)->names('adjustments');
        Route::post('/adjustments/{adjustment}/submit', [\App\Http\Controllers\StockAdjustmentController::class, 'submitForApproval'])->name('adjustments.submit');
        Route::post('/adjustments/{adjustment}/approve', [\App\Http\Controllers\StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/reject', [\App\Http\Controllers\StockAdjustmentController::class, 'reject'])->name('adjustments.reject');
        Route::get('/adjustment-products', [\App\Http\Controllers\StockAdjustmentController::class, 'getProductsForAdjustment'])->name('adjustment-products');
        
        Route::resource('locations', \App\Http\Controllers\InventoryLocationController::class)->names('locations');
        Route::post('/locations/{location}/set-default', [\App\Http\Controllers\InventoryLocationController::class, 'setDefault'])->name('locations.set-default');
        Route::post('/locations/{location}/toggle-status', [\App\Http\Controllers\InventoryLocationController::class, 'toggleStatus'])->name('locations.toggle-status');
    });
    
    // Terminal Setup (Organization-specific)
    Route::post('/setup-terminal', [\App\Http\Controllers\TerminalController::class, 'setupTerminal'])->name('setup.terminal');
});

// Domain Management Routes (for super users)
Route::middleware(['auth', 'user.permission'])->prefix('domains')->name('domains.')->group(function () {
    Route::get('/', [\App\Http\Controllers\DomainController::class, 'index'])->name('index');
    Route::get('/create', [\App\Http\Controllers\DomainController::class, 'create'])->name('create');
    Route::post('/', [\App\Http\Controllers\DomainController::class, 'store'])->name('store');
    Route::get('/{domain}', [\App\Http\Controllers\DomainController::class, 'show'])->name('show');
    Route::get('/{domain}/edit', [\App\Http\Controllers\DomainController::class, 'edit'])->name('edit');
    Route::put('/{domain}', [\App\Http\Controllers\DomainController::class, 'update'])->name('update');
    Route::delete('/{domain}', [\App\Http\Controllers\DomainController::class, 'destroy'])->name('destroy');
});

// Global routes (not domain-specific)
Route::middleware(['auth', 'user.permission'])->group(function () {
    // User Management (global - not domain specific)
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
        ->middleware('auth')
        ->name('supervisors.cascading-options');
    Route::post('/supervisors/{supervisor}/cascading-assign', [\App\Http\Controllers\SupervisorAssignmentController::class, 'cascadingAssign'])
        ->middleware('auth')
        ->name('supervisors.cascading-assign');

    // Role Management (Only for super user)
    Route::get('/roles', [\App\Http\Controllers\RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/create', [\App\Http\Controllers\RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [\App\Http\Controllers\RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'show'])->name('roles.show');
    Route::get('/roles/{role}/edit', [\App\Http\Controllers\RoleController::class, 'edit'])->name('roles.edit');
    Route::put('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'destroy'])->name('roles.destroy');
    Route::get('/roles-permissions/matrix', [\App\Http\Controllers\RoleController::class, 'permissionMatrix'])->name('roles.permission-matrix');

    // Permission Management (Only for super user)
    Route::resource('permissions', \App\Http\Controllers\PermissionController::class)->names('permissions');
    Route::post('/permissions/{permission}/activate', [\App\Http\Controllers\PermissionController::class, 'activate'])->name('permissions.activate');
    Route::post('/permissions/{permission}/deactivate', [\App\Http\Controllers\PermissionController::class, 'deactivate'])->name('permissions.deactivate');
});

Route::get('/customer-order', function () {
    return Inertia::render('CustomerOrderView');
})->name('customer-order');

// Debug routes
Route::get('/debug-super-user', function () {
    $user = auth()->user();
    return response()->json([
        'user_id' => $user->id,
        'user_name' => $user->name,
        'is_super_user_field' => $user->is_super_user,
        'isSuperUser_method' => $user->isSuperUser(),
        'method_exists' => method_exists($user, 'isSuperUser')
    ]);
})->middleware('auth');

Route::get('/debug-role-hierarchy', function () {
    $hierarchy = \App\Services\UserHierarchyService::getRoleHierarchyInfo();
    $usersWithoutSupervisors = \App\Models\User::whereNull('supervisor_id')
        ->where('is_super_user', false)
        ->with('roles')
        ->get()
        ->map(function ($user) {
            $role = $user->roles()->orderBy('level')->first();
            return [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $role ? $role->name : 'No Role',
                'level' => $role ? $role->level : null,
                'is_super_user' => $user->is_super_user
            ];
        });
    
    return response()->json([
        'role_hierarchy' => $hierarchy,
        'users_without_supervisors' => $usersWithoutSupervisors
    ]);
})->middleware('auth');

require __DIR__ . '/auth.php';
