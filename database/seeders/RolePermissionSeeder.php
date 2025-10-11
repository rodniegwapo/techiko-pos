<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions based on actual route names from ->name() method calls
        $permissions = [
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',
            
            // User Management
            'users.index',
            'users.hierarchy',
            'users.show',
            'users.edit',
            'users.assign-supervisor',
            'users.remove-supervisor',
            'users.supervisor-history',
            'supervisors.available',
            'supervisors.available-for-user',
            'supervisors.auto-assign',
            'supervisors.cascading-options',
            'supervisors.cascading-assign',
            'users.store',
            'users.store',
            'users.show',
            'users.update',
            'users.destroy',
            'users.roles',
            'users.toggle-status',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.sales.syncDraft',
            'sales.sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            'products.store',
            'products.update',
            'products.destroy',
            'products.create',
            'products.edit',
            
            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.destroy',
            'categories.create',
            'categories.edit',
            
            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',
            
            // Discounts
            'products.discounts.index',
            'products.discounts.store',
            'products.discounts.update',
            'products.discounts.destroy',
            'products.discounts.create',
            'products.discounts.edit',
            
            // Mandatory Discounts
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            'mandatory-discounts.create',
            'mandatory-discounts.edit',
            
            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.store',
            'loyalty.tiers.show',
            'loyalty.tiers.update',
            'loyalty.tiers.destroy',
            
            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.adjustment-products',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.create',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.destroy',
            'inventory.adjustments.edit',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.create',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.edit',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            
            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            'inventory.search.locations',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',
            
            // Void Logs
            'voids.index',
            
            // Terminal Setup
            'setup.terminal',
            
            // Role Management (super user only)
            'roles.index',
            'roles.create',
            'roles.store',
            'roles.show',
            'roles.edit',
            'roles.update',
            'roles.destroy',
            'roles.permission-matrix',
            'roles.permissions',
            
            // Permission Management (super user only)
            'permissions.index',
            'permissions.store',
            'permissions.show',
            'permissions.update',
            'permissions.deactivate',
            'permissions.activate',
            'permissions.bulk-deactivate',
            'permissions.grouped',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
            
            // Customer Order
            'customer-order',
        ];

        $usedNames = [];
        $createdPermissions = [];
        
        foreach ($permissions as $permission) {
            $displayName = $this->generateDisplayName($permission);
            
            // Handle duplicate display names by adding a counter
            $counter = 1;
            $originalDisplayName = $displayName;
            while (in_array($displayName, $usedNames)) {
                $displayName = $originalDisplayName . " ({$counter})";
                $counter++;
            }
            
            $createdPermission = Permission::firstOrCreate([
                'name' => $displayName, // Human-readable name
                'route_name' => $permission, // Technical route name
                'guard_name' => 'web'
            ]);
            
            $createdPermissions[] = $createdPermission;
            $usedNames[] = $displayName;
        }

        // Create roles and assign permissions
        
        // Super Admin - Full access
        $superAdmin = Role::findOrCreate('super admin', 'web');
        $superAdmin->update(['level' => 1, 'description' => 'Full system access with all permissions']);
        $superAdmin->syncPermissions(collect($createdPermissions)->pluck('name')->toArray());
        
        // Admin - Most permissions except system settings
        $admin = Role::findOrCreate('admin', 'web');
        $admin->update(['level' => 2, 'description' => 'Administrative access with user management capabilities']);
        $admin->syncPermissions($this->getPermissionsByRouteNames([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',
            
            // User Management
            'users.index',
            'users.hierarchy',
            'users.show',
            'users.edit',
            'users.assign-supervisor',
            'users.remove-supervisor',
            'users.supervisor-history',
            'supervisors.available',
            'supervisors.available-for-user',
            'supervisors.auto-assign',
            'supervisors.cascading-options',
            'supervisors.cascading-assign',
            'users.store',
            'users.store',
            'users.show',
            'users.update',
            'users.destroy',
            'users.roles',
            'users.toggle-status',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.sales.syncDraft',
            'sales.sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            'products.store',
            'products.update',
            'products.destroy',
            'products.create',
            'products.edit',
            
            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.destroy',
            'categories.create',
            'categories.edit',
            
            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',
            
            // Discounts
            'products.discounts.index',
            'products.discounts.store',
            'products.discounts.update',
            'products.discounts.destroy',
            'products.discounts.create',
            'products.discounts.edit',
            
            // Mandatory Discounts
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            'mandatory-discounts.create',
            'mandatory-discounts.edit',
            
            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.store',
            'loyalty.tiers.show',
            'loyalty.tiers.update',
            'loyalty.tiers.destroy',
            
            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.adjustment-products',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.create',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.destroy',
            'inventory.adjustments.edit',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.create',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.edit',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            
            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            'inventory.search.locations',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',
            
            // Void Logs
            'voids.index',
            
            // Terminal Setup
            'setup.terminal',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
            
            // Customer Order
            'customer-order',
        ], $createdPermissions));
        
        // Manager - Operational permissions
        $manager = Role::findOrCreate('manager', 'web');
        $manager->update(['level' => 3, 'description' => 'Operational management with reporting and staff oversight']);
        $manager->syncPermissions($this->getPermissionsByRouteNames([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.sales.syncDraft',
            'sales.sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            'products.store',
            'products.update',
            'products.create',
            'products.edit',
            
            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.create',
            'categories.edit',
            
            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',
            
            // Discounts
            'products.discounts.index',
            
            // Mandatory Discounts (manager read-only)
            'mandatory-discounts.index',
            
            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',
            
            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.adjustment-products',
            'inventory.adjustments.index',
            'inventory.adjustments.show',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.locations.index',
            'inventory.locations.show',
            
            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',
            'inventory.adjustments.index',
            'inventory.adjustments.show',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',
            
            // Void Logs
            'voids.index',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
            
            // Customer Order
            'customer-order',
        ], $createdPermissions));
        
        // Supervisor - Shift supervision permissions
        $supervisor = Role::findOrCreate('supervisor', 'web');
        $supervisor->update(['level' => 4, 'description' => 'Shift supervision with limited management capabilities']);
        $supervisor->syncPermissions($this->getPermissionsByRouteNames([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',
            
            // User Management (view only)
            'users.index',
            'users.show',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.sales.syncDraft',
            'sales.sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            'products.store',
            'products.update',
            'products.create',
            'products.edit',
            
            // Categories
            'categories.index',
            
            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',
            
            // Discounts
            'products.discounts.index',
            
            // Mandatory Discounts (view only)
            'mandatory-discounts.index',
            
            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',
            
            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.locations.index',
            'inventory.locations.show',
            
            // Inventory API
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',
            
            // Void Logs
            'voids.index',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
            
            // Customer Order
            'customer-order',
        ], $createdPermissions));
        
        // Cashier - Basic sales permissions
        $cashier = Role::findOrCreate('cashier', 'web');
        $cashier->update(['level' => 5, 'description' => 'Front-line operations with sales processing capabilities']);
        $cashier->syncPermissions($this->getPermissionsByRouteNames([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.sales.syncDraft',
            'sales.sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            
            // Customers
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',
            
            // Discounts
            'products.discounts.index',
            
            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',
            
            // Inventory Management (view only)
            'inventory.index',
            'inventory.products',
            'inventory.locations.index',
            'inventory.locations.show',
            
            // Inventory API
            'inventory.products',
            'inventory.search.products',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
            
            // Customer Order
            'customer-order',
        ], $createdPermissions));

        $this->command->info('Roles and permissions created successfully!');
    }

    /**
     * Get permission names by route names
     */
    private function getPermissionsByRouteNames(array $routeNames, array $createdPermissions): array
    {
        return collect($createdPermissions)
            ->filter(function ($permission) use ($routeNames) {
                return in_array($permission->route_name, $routeNames);
            })
            ->pluck('name')
            ->toArray();
    }

    /**
     * Generate a display name from route name
     */
    private function generateDisplayName(string $routeName): string
    {
        $parts = explode('.', $routeName);
        
        if (count($parts) >= 3) {
            // Handle nested resources (3+ parts: module.submodule.action)
            $module = $parts[0];
            $submodule = $parts[1];
            $action = $parts[2];
            
            $actionLabels = [
                'index' => 'View',
                'create' => 'Create',
                'store' => 'Create',
                'edit' => 'Edit',
                'update' => 'Update',
                'show' => 'View',
                'destroy' => 'Delete',
                'delete' => 'Delete',
                'apply' => 'Apply',
                'manage' => 'Manage',
                'adjust_points' => 'Adjust Points',
                'export' => 'Export',
                'dashboard' => 'Dashboard',
                'movements' => 'Movements',
                'adjustments' => 'Adjustments',
                'valuation' => 'Valuation',
                'receive' => 'Receive',
                'transfer' => 'Transfer',
                'low_stock' => 'Low Stock',
                'tiers_manage' => 'Manage Tiers',
                'customers_manage' => 'Manage Customers',
                'points_adjust' => 'Adjust Points',
                'reports_view' => 'View Reports',
                'set-default' => 'Set Default',
                'toggle-status' => 'Toggle Status',
                'summary' => 'Summary',
                'search' => 'Search',
                'submit' => 'Submit',
                'approve' => 'Approve',
                'reject' => 'Reject',
            ];
            
            $submoduleLabels = [
                'locations' => 'Location',
                'categories' => 'Category',
                'adjustments' => 'Adjustment',
                'movements' => 'Movement',
                'products' => 'Product',
                'tiers' => 'Tier',
                'customers' => 'Customer',
                'discounts' => 'Discount',
                'orders' => 'Order',
                'sales' => 'Sale',
                'loyalty' => 'Loyalty',
                'permissions' => 'Permission',
                'roles' => 'Role',
                'users' => 'User',
                'voids' => 'Void',
                'terminals' => 'Terminal',
            ];
            
            $actionLabel = $actionLabels[$action] ?? ucfirst($action);
            $submoduleLabel = $submoduleLabels[$submodule] ?? ucfirst($submodule);
            
            return "{$actionLabel} {$submoduleLabel}";
        }
        
        // Handle simple permissions (2 parts: module.action)
        if (count($parts) === 2) {
            $module = $parts[0];
            $action = $parts[1];
            
            $actionLabels = [
                'index' => 'View',
                'create' => 'Create',
                'store' => 'Create',
                'edit' => 'Edit',
                'update' => 'Update',
                'show' => 'View',
                'destroy' => 'Delete',
                'delete' => 'Delete',
                'apply' => 'Apply',
                'manage' => 'Manage',
                'adjust_points' => 'Adjust Points',
                'export' => 'Export',
                'dashboard' => 'Dashboard',
                'products' => 'Products',
                'movements' => 'Movements',
                'adjustments' => 'Adjustments',
                'locations' => 'Locations',
                'valuation' => 'Valuation',
                'receive' => 'Receive',
                'transfer' => 'Transfer',
                'low_stock' => 'Low Stock',
                'tiers_manage' => 'Manage Tiers',
                'customers_manage' => 'Manage Customers',
                'points_adjust' => 'Adjust Points',
                'reports_view' => 'View Reports',
            ];
            
            $moduleLabels = [
                'users' => 'Users',
                'roles' => 'Roles',
                'permissions' => 'Permissions',
                'products' => 'Products',
                'categories' => 'Categories',
                'customers' => 'Customers',
                'sales' => 'Sales',
                'inventory' => 'Inventory',
                'loyalty' => 'Loyalty',
                'discounts' => 'Discounts',
                'reports' => 'Reports',
                'settings' => 'Settings',
                'dashboard' => 'Dashboard',
                'voids' => 'Void Logs',
                'orders' => 'Orders',
                'supervisors' => 'Supervisors',
                'mandatory-discounts' => 'Mandatory Discounts',
                'setup' => 'Setup',
            ];

            $actionLabel = $actionLabels[$action] ?? ucfirst($action);
            $moduleLabel = $moduleLabels[$module] ?? ucfirst($module);
            
            return "{$actionLabel} {$moduleLabel}";
        }
        
        return ucfirst($routeName);
    }
}