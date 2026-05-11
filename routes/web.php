<?php

use App\Http\Controllers\Billing\AdminManualPaymentsController;
use App\Http\Controllers\Catalog\SharedProductController;
use App\Http\Controllers\Catalog\SharedProductSuggestionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\MandatoryDiscountController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Products\DiscountController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\SupervisorAssignmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoidLogController;
use App\Http\Controllers\Webhooks\PayMongoWebhookController;
use App\Models\User;
use App\Services\UserHierarchyService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Public marketing (SEO) – `/login` is the canonical auth entry (see routes/auth.php)
Route::get('/', [MarketingController::class, 'home'])->name('home');
Route::get('/services', [MarketingController::class, 'services'])->name('marketing.services');
Route::get('/about', [MarketingController::class, 'about'])->name('marketing.about');
Route::get('/contact', [MarketingController::class, 'contact'])->name('marketing.contact');
//Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');

Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
// Source of truth is public/robots.txt (nginx often serves it before PHP). Route keeps tests / non-nginx stacks working.
Route::get('/robots.txt', function () {
    $path = public_path('robots.txt');
    abort_unless(file_exists($path), 404);

    return response(
        file_get_contents($path),
        200,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
    );
})->name('robots');

Route::post('/webhooks/paymongo', [PayMongoWebhookController::class, 'handle'])->name('webhooks.paymongo');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // User Impersonation (Super Users Only)
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'impersonate'])
        ->name('impersonate');
    Route::post('/stop-impersonating', [ImpersonationController::class, 'stopImpersonating'])
        ->name('stop-impersonating');
});

// ===================================
// GLOBAL ROUTES (Super Users Only)
// ===================================
Route::middleware(['auth', 'verified', 'user.permission'])->group(function () {
    // Sales (Global - All Organizations)
    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');

    // Products (Global - All Organizations)
    Route::resource('products', ProductController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
        ->names('products');

    // Categories (Global - All Organizations)
    Route::resource('categories', CategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('categories');

    // Product Discounts (Global)
    Route::name('products.')->group(function () {
        Route::resource('discounts', DiscountController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('discounts');
    });

    // Mandatory Discounts (Global)
    Route::resource('mandatory-discounts', MandatoryDiscountController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('mandatory-discounts');

    // Loyalty Program (Global)
    Route::get('/loyalty', [LoyaltyController::class, 'index'])->name('loyalty.index');

    // Customers (Global)
    Route::get('/customers', [CustomerController::class, 'webIndex'])->name('customers.index');

    // Void Logs (Global)
    Route::get('/void-logs', [VoidLogController::class, 'index'])->name('voids.index');

    // Shared product catalog (super-only; controllers enforce isSuperUser)
    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::resource('shared-products', SharedProductController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('shared-product-suggestions', [SharedProductSuggestionController::class, 'index'])->name('shared-product-suggestions.index');
        Route::post('shared-product-suggestions/{shared_product_suggestion}/accept', [SharedProductSuggestionController::class, 'accept'])->name('shared-product-suggestions.accept');
        Route::post('shared-product-suggestions/{shared_product_suggestion}/reject', [SharedProductSuggestionController::class, 'reject'])->name('shared-product-suggestions.reject');
    });

    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/manual-payments', [AdminManualPaymentsController::class, 'index'])->name('manual-payments.index');
        Route::post('/manual-payments/{manual_payment_request}/approve', [AdminManualPaymentsController::class, 'approve'])->name('manual-payments.approve');
        Route::post('/manual-payments/{manual_payment_request}/reject', [AdminManualPaymentsController::class, 'reject'])->name('manual-payments.reject');
    });

    // Inventory Management (Global)
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
        Route::post('/locations/{location}/set-default', [InventoryLocationController::class, 'setDefault'])->name('locations.set-default');
        Route::post('/locations/{location}/toggle-status', [InventoryLocationController::class, 'toggleStatus'])->name('locations.toggle-status');
    });
});

// Removed pending-approval route; we block at login instead if domain inactive

// Registration Thank You page (no auth, public)
Route::get('/thank-you', function () {
    return inertia('Auth/RegistrationThankYou');
})->name('registration.thankyou');

// Organization-specific routes extracted to routes/domains.php
require __DIR__ . '/domains.php';

// Domain Management Routes (for super users)
Route::middleware(['auth', 'verified', 'user.permission'])->prefix('domains')->name('domains.')->group(function () {
    Route::get('/', [DomainController::class, 'index'])->name('index');
    Route::get('/create', [DomainController::class, 'create'])->name('create');
    Route::post('/', [DomainController::class, 'store'])->name('store');
    Route::get('/{domain}', [DomainController::class, 'show'])->name('show');
    Route::get('/{domain}/edit', [DomainController::class, 'edit'])->name('edit');
    Route::put('/{domain}', [DomainController::class, 'update'])->name('update');
    Route::delete('/{domain}', [DomainController::class, 'destroy'])->name('destroy');
    Route::post('/{domain}/toggle-status', [DomainController::class, 'toggleStatus'])->name('toggle-status');
});

// Global routes (not domain-specific)
Route::middleware(['auth', 'verified', 'user.permission'])->group(function () {
    // User Management (global - not domain specific)
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/hierarchy', [UserController::class, 'hierarchy'])->name('users.hierarchy');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');

    // Supervisor Assignment Routes (Level-based)
    Route::post('/users/{user}/assign-supervisor', [SupervisorAssignmentController::class, 'assign'])
        ->name('users.assign-supervisor');
    Route::delete('/users/{user}/remove-supervisor', [SupervisorAssignmentController::class, 'remove'])
        ->name('users.remove-supervisor');
    Route::get('/supervisors/available', [SupervisorAssignmentController::class, 'availableSupervisors'])
        ->name('supervisors.available');
    Route::get('/supervisors/available/{user}', [SupervisorAssignmentController::class, 'availableSupervisors'])
        ->name('supervisors.available-for-user');
    Route::post('/supervisors/auto-assign', [SupervisorAssignmentController::class, 'autoAssign'])
        ->name('supervisors.auto-assign');
    Route::get('/users/{user}/supervisor-history', [SupervisorAssignmentController::class, 'history'])
        ->name('users.supervisor-history');

    // Cascading Assignment Routes
    Route::get('/supervisors/cascading-options', [SupervisorAssignmentController::class, 'cascadingOptions'])
        ->name('supervisors.cascading-options');
    Route::post('/supervisors/{supervisor}/cascading-assign', [SupervisorAssignmentController::class, 'cascadingAssign'])
        ->name('supervisors.cascading-assign');

    // Role Management (Only for super user)
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    Route::get('/roles-permissions/matrix', [RoleController::class, 'permissionMatrix'])->name('roles.permission-matrix');

    // Permission Management (Only for super user)
    Route::resource('permissions', PermissionController::class)->names('permissions');
    Route::post('/permissions/{permission}/activate', [PermissionController::class, 'activate'])->name('permissions.activate');
    Route::post('/permissions/{permission}/deactivate', [PermissionController::class, 'deactivate'])->name('permissions.deactivate');
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
        'method_exists' => method_exists($user, 'isSuperUser'),
    ]);
})->middleware(['auth', 'verified']);

Route::get('/debug-role-hierarchy', function () {
    $hierarchy = UserHierarchyService::getRoleHierarchyInfo();
    $usersWithoutSupervisors = User::whereNull('supervisor_id')
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
                'is_super_user' => $user->is_super_user,
            ];
        });

    return response()->json([
        'role_hierarchy' => $hierarchy,
        'users_without_supervisors' => $usersWithoutSupervisors,
    ]);
})->middleware(['auth', 'verified']);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/inquiries', [ConversationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('inquiries.store');
    Route::get('/api/conversations/{conversation}/messages', [ConversationController::class, 'messagesJson'])
        ->name('conversations.messages');
});

Route::middleware(['auth', 'verified', 'check.super.user'])->group(function () {
    Route::get('/messages', [ConversationController::class, 'index'])->name('messages.index');
    Route::post('/messages/{conversation}/read', [ConversationController::class, 'markRead'])->name('messages.read');
    Route::post('/messages/{conversation}/messages', [ConversationController::class, 'storeStaff'])->name('messages.staff');
});

require __DIR__ . '/auth.php';
