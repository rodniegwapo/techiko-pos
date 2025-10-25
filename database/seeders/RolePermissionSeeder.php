<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',
            
            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            
            // Current Pending Sale
            'sales.current-pending',
            
            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',
            
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
            'permissions.destroy',
            'permissions.deactivate',
            'permissions.activate',
            'permissions.bulk-deactivate',
            'permissions.grouped',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
            
            // Customer Order
            'customer-order',
            
            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',
            
            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
        ];

        // Get all permission modules
        $modules = DB::table('permission_modules')->get()->keyBy('name');
        
        foreach ($permissions as $permission) {
            $parts = explode('.', $permission);
            $moduleName = $parts[0];
            $action = $parts[count($parts) - 1]; // Get the last part as action
            
            // Get module ID
            $module = $modules->get($moduleName);
            $moduleId = $module ? $module->id : null;
            
            // Generate display name (just the action)
            $displayName = $this->getActionDisplayName($action);
            
            // Use just the action name since module is already shown as section header
            $uniqueName = $displayName;
            
            // If we already have a permission with this name, make it unique by adding route info
            $existingPermission = Permission::where('name', $uniqueName)->where('guard_name', 'web')->first();
            if ($existingPermission) {
                $uniqueName = $uniqueName . ' (' . $permission . ')';
            }
            
            Permission::firstOrCreate([
                'route_name' => $permission, // Technical route: "users.index"
                'guard_name' => 'web'
            ], [
                'name' => $uniqueName, // "Users - View", "Products - Create"
                'action' => $action, // Action: "index", "create", "edit"
                'module_id' => $moduleId, // Foreign key to permission_modules
            ]);
        }

        // Create roles and assign permissions
        
        // Super Admin - Full access
        $superAdmin = Role::findOrCreate('super admin', 'web');
        $superAdmin->update(['level' => 1, 'description' => 'Full system access with all permissions']);
        $superAdmin->syncPermissions(Permission::all()->pluck('name')->toArray());
        
        // Admin - Most permissions except system settings
        $admin = Role::findOrCreate('admin', 'web');
        $admin->update(['level' => 2, 'description' => 'Administrative access with user management capabilities']);
        $admin->syncPermissions($this->getPermissionNamesByRoutes([
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
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',
            
            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            
            // Current Pending Sale
            'sales.current-pending',
            
            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',
            
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
        ]));
        
        // Manager - Operational permissions
        $manager = Role::findOrCreate('manager', 'web');
        $manager->update(['level' => 3, 'description' => 'Operational management with reporting and staff oversight']);
        $manager->syncPermissions($this->getPermissionNamesByRoutes([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',
            
            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            
            // Current Pending Sale
            'sales.current-pending',
            
            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',
            
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
        ]));
        
        // Supervisor - Shift supervision permissions
        $supervisor = Role::findOrCreate('supervisor', 'web');
        $supervisor->update(['level' => 4, 'description' => 'Shift supervision with limited management capabilities']);
        $supervisor->syncPermissions($this->getPermissionNamesByRoutes([
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
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',
            
            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            
            // Current Pending Sale
            'sales.current-pending',
            
            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',
            
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
        ]));
        
        // Cashier - Basic sales permissions
        $cashier = Role::findOrCreate('cashier', 'web');
        $cashier->update(['level' => 5, 'description' => 'Front-line operations with sales processing capabilities']);
        $cashier->syncPermissions($this->getPermissionNamesByRoutes([
            // Dashboard
            'dashboard',
            'dashboard.sales-chart',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.find-sale-item',
            'sales.sales.assignCustomer',
            'sales.sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Cart Management - Database-driven
            'sales.cart.add',
            'sales.cart.remove',
            'sales.cart.update-quantity',
            'sales.cart.state',
            
            // Discount Management - Database-driven
            'sales.discounts.current',
            'sales.discounts.sale',
            'sales.discounts.update',
            
            // Current Pending Sale
            'sales.current-pending',
            
            // User-specific sales routes
            'users.sales.create',
            'users.sales.cart.add',
            'users.sales.current-pending',
            'users.sales.cart.update-quantity',
            'users.sales.cart.remove',
            'users.sales.cart.state',
            
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
        ]));

        $this->command->info('Roles and permissions created successfully!');
    }

    /**
     * Get display name for action
     */
    private function getActionDisplayName(string $action): string
    {
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
            'export' => 'Export',
            'dashboard' => 'Dashboard',
            'search' => 'Search',
            'approve' => 'Approve',
            'reject' => 'Reject',
            'submit' => 'Submit',
            'toggle-status' => 'Toggle',
            'set-default' => 'Set Default',
            'hierarchy' => 'Hierarchy',
            'assign-supervisor' => 'Assign Supervisor',
            'remove-supervisor' => 'Remove Supervisor',
            'supervisor-history' => 'Supervisor History',
            'available' => 'Available',
            'available-for-user' => 'Available For User',
            'auto-assign' => 'Auto Assign',
            'cascading-options' => 'Cascading Options',
            'cascading-assign' => 'Cascading Assign',
            'toggle-status' => 'Toggle Status',
            'syncDraft' => 'Sync Draft',
            'syncDraftImmediate' => 'Sync Draft Immediate',
            'find-sale-item' => 'Find Sale Item',
            'assignCustomer' => 'Assign Customer',
            'processLoyalty' => 'Process Loyalty',
            'void' => 'Void',
            'store' => 'Store',
            'products' => 'Products',
            'movements' => 'Movements',
            'low-stock' => 'Low Stock',
            'valuation' => 'Valuation',
            'receive' => 'Receive',
            'transfer' => 'Transfer',
            'adjustment-products' => 'Adjustment Products',
            'adjustments' => 'Adjustments',
            'locations' => 'Locations',
            'summary' => 'Summary',
            'stats' => 'Stats',
            'customers' => 'Customers',
            'analytics' => 'Analytics',
            'adjust-points' => 'Adjust Points',
            'tiers' => 'Tiers',
            'permission-matrix' => 'Permission Matrix',
            'permissions' => 'Permissions',
            'deactivate' => 'Deactivate',
            'activate' => 'Activate',
            'bulk-deactivate' => 'Bulk Deactivate',
            'grouped' => 'Grouped',
            'view' => 'View',
            'recent-pending' => 'Recent Pending',
            'customer-order' => 'Customer Order',
            
            // Cart Management Actions
            'cart.add' => 'Add to Cart',
            'cart.remove' => 'Remove from Cart',
            'cart.update-quantity' => 'Update Quantity',
            'cart.state' => 'View Cart State',
            
            // Discount Management Actions
            'discounts.current' => 'View Current Discounts',
            'discounts.sale' => 'View Sale Discounts',
            'discounts.update' => 'Update Discounts',
        ];

        return $actionLabels[$action] ?? ucfirst($action);
    }

    /**
     * Get permission names by route names
     */
    private function getPermissionNamesByRoutes(array $routeNames): array
    {
        return Permission::whereIn('route_name', $routeNames)
            ->pluck('name')
            ->toArray();
    }

}