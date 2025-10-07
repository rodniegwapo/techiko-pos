<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UserPermissionCheckMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        // Super user has all permissions
        if ($user->isSuperUser()) {
            return $next($request);
        }

        // Special-case: allow supervisors to view Users index/show without explicit users.view
        $routeName = $request->route()?->getName();
        if ($user->hasRole('supervisor') && in_array($routeName, ['users.index', 'users.show'])) {
            return $next($request);
        }

        // Get the required permission for this route
        $requiredPermission = $this->getRequiredPermission($request);

        // If no permission is required, allow access
        if (!$requiredPermission) {
            return $next($request);
        }

        // Check if user has the required permission
        if (!$user->hasPermissionTo($requiredPermission)) {
            return $this->handleUnauthorized($request);
        }

        return $next($request);
    }

    /**
     * Determine the required permission based on the current route
     */
    private function getRequiredPermission(Request $request): ?string
    {
        $routeName = $request->route()?->getName();
        $controller = $request->route()?->getController();
        $action = $request->route()?->getActionMethod();

        // Define permission mapping based on route names
        $routePermissionMap = [
            // Dashboard
            'dashboard' => 'dashboard.view',

            // Users
            'users.index' => 'users.view',
            'users.create' => 'users.create',
            'users.store' => 'users.create',
            'users.show' => 'users.view',
            'users.edit' => 'users.edit',
            'users.update' => 'users.edit',
            'users.destroy' => 'users.delete',

            // Roles
            'roles.index' => 'roles.view',
            'roles.create' => 'roles.create',
            'roles.store' => 'roles.create',
            'roles.show' => 'roles.view',
            'roles.edit' => 'roles.edit',
            'roles.update' => 'roles.edit',
            'roles.destroy' => 'roles.delete',
            'roles.permission-matrix' => 'roles.view',

            // Categories
            'categories.index' => 'categories.view',
            'categories.create' => 'categories.create',
            'categories.store' => 'categories.create',
            'categories.show' => 'categories.view',
            'categories.edit' => 'categories.edit',
            'categories.update' => 'categories.edit',
            'categories.destroy' => 'categories.delete',

            // Products
            'products.index' => 'products.view',
            'products.create' => 'products.create',
            'products.store' => 'products.create',
            'products.show' => 'products.view',
            'products.edit' => 'products.edit',
            'products.update' => 'products.edit',
            'products.destroy' => 'products.delete',

            // Sales
            'sales.index' => 'sales.view',
            'sales.create' => 'sales.create',
            'sales.store' => 'sales.create',
            'sales.show' => 'sales.view',
            'sales.edit' => 'sales.edit',
            'sales.update' => 'sales.edit',
            'sales.destroy' => 'sales.delete',
            'sales.void' => 'sales.void',
            'sales.products' => 'sales.view',
            'sales.drafts.store' => 'sales.create',
            'sales.items.void' => 'sales.void',
            'sales.payment.store' => 'sales.create',
            'sales.sales.syncDraft' => 'sales.create',
            'sales.sales.syncDraftImmediate' => 'sales.create',
            'sales.find-sale-item' => 'sales.view',
            'sales.sales.assignCustomer' => 'sales.create',
            'sales.sales.processLoyalty' => 'sales.create',
            'sales.discounts.order.apply' => 'sales.create',
            'sales.discounts.order.remove' => 'sales.create',
            'sales.items.discount.apply' => 'sales.create',
            'sales.items.discount.remove' => 'sales.create',

            // Customers
            'customers.index' => 'customers.view',
            'customers.create' => 'customers.create',
            'customers.store' => 'customers.create',
            'customers.show' => 'customers.view',
            'customers.edit' => 'customers.edit',
            'customers.update' => 'customers.edit',
            'customers.destroy' => 'customers.delete',

            // Inventory
            'inventory.index' => 'inventory.view',
            'inventory.dashboard' => 'inventory.dashboard',
            'inventory.products' => 'inventory.products',
            'inventory.movements' => 'inventory.movements',
            'inventory.adjustments' => 'inventory.adjustments',
            'inventory.locations' => 'inventory.locations',
            'inventory.receive' => 'inventory.receive',
            'inventory.transfer' => 'inventory.transfer',
            'inventory.low-stock' => 'inventory.low_stock',

            // Stock Adjustments
            'stock-adjustments.index' => 'inventory.adjustments',
            'stock-adjustments.create' => 'inventory.adjustments',
            'stock-adjustments.store' => 'inventory.adjustments',
            'stock-adjustments.show' => 'inventory.adjustments',
            'stock-adjustments.edit' => 'inventory.adjustments',
            'stock-adjustments.update' => 'inventory.adjustments',
            'stock-adjustments.destroy' => 'inventory.adjustments',

            // Loyalty
            'loyalty.index' => 'loyalty.view',
            'loyalty.customers' => 'loyalty.customers_manage',
            'loyalty.points' => 'loyalty.points_adjust',
            'loyalty.tiers' => 'loyalty.tiers_manage',
            'loyalty.reports' => 'loyalty.reports_view',

            // Reports
            'reports.index' => 'reports.view',
            'reports.export' => 'reports.export',

            // Void Logs
            'void-logs.index' => 'void_logs.view',
        ];

        // Check route name mapping first
        if ($routeName && isset($routePermissionMap[$routeName])) {
            return $routePermissionMap[$routeName];
        }

        // Fallback: Generate permission based on controller and action
        if ($controller && $action) {
            $controllerName = class_basename($controller);
            $module = $this->getModuleFromController($controllerName);

            if ($module) {
                return $this->generatePermissionFromAction($module, $action);
            }
        }

        return null;
    }

    /**
     * Extract module name from controller
     */
    private function getModuleFromController(string $controllerName): ?string
    {
        $controllerMap = [
            'UserController' => 'users',
            'RoleController' => 'roles',
            'CategoryController' => 'categories',
            'ProductController' => 'products',
            'SaleController' => 'sales',
            'CustomerController' => 'customers',
            'InventoryController' => 'inventory',
            'StockAdjustmentController' => 'inventory',
            'LoyaltyController' => 'loyalty',
            'ReportController' => 'reports',
            'VoidLogController' => 'void_logs',
        ];

        return $controllerMap[$controllerName] ?? null;
    }

    /**
     * Generate permission name from module and action
     */
    private function generatePermissionFromAction(string $module, string $action): string
    {
        $actionMap = [
            'index' => 'view',
            'show' => 'view',
            'create' => 'create',
            'store' => 'create',
            'edit' => 'edit',
            'update' => 'edit',
            'destroy' => 'delete',
            'void' => 'void',
        ];

        $permissionAction = $actionMap[$action] ?? $action;

        return "{$module}.{$permissionAction}";
    }

    /**
     * Handle unauthorized access
     */
    private function handleUnauthorized(Request $request): Response
    {
        // If it's an API request, return JSON error
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You do not have permission to access this resource.',
                'error' => 'Forbidden'
            ], 403);
        }

        // For web requests, redirect with error message
        return redirect()->back()->with('error', 'You do not have permission to access this page.');
    }
}
