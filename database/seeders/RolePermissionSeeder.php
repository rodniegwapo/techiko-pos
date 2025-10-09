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

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign permissions
        
        // Super Admin - Full access
        $superAdmin = Role::findOrCreate('super admin', 'web');
        $superAdmin->update(['level' => 1, 'description' => 'Full system access with all permissions']);
        $superAdmin->syncPermissions(Permission::all());
        
        // Admin - Most permissions except system settings
        $admin = Role::findOrCreate('admin', 'web');
        $admin->update(['level' => 2, 'description' => 'Administrative access with user management capabilities']);
        $admin->syncPermissions([
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
        ]);
        
        // Manager - Operational permissions
        $manager = Role::findOrCreate('manager', 'web');
        $manager->update(['level' => 3, 'description' => 'Operational management with reporting and staff oversight']);
        $manager->syncPermissions([
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
        ]);
        
        // Supervisor - Shift supervision permissions
        $supervisor = Role::findOrCreate('supervisor', 'web');
        $supervisor->update(['level' => 4, 'description' => 'Shift supervision with limited management capabilities']);
        $supervisor->syncPermissions([
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
        ]);
        
        // Cashier - Basic sales permissions
        $cashier = Role::findOrCreate('cashier', 'web');
        $cashier->update(['level' => 5, 'description' => 'Front-line operations with sales processing capabilities']);
        $cashier->syncPermissions([
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
        ]);

        $this->command->info('Roles and permissions created successfully!');
    }
}