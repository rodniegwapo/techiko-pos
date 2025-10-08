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

        // Create permissions based on actual routes in web.php and api.php
        $permissions = [
            // Dashboard
            'dashboard.index',
            
            // User Management (from web.php)
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
            
            // Sales (from web.php and api.php)
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.syncDraft',
            'sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.assignCustomer',
            'sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products (from web.php)
            'products.index',
            'products.store',
            'products.update',
            'products.destroy',
            
            // Categories (from web.php)
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.destroy',
            
            // Customers (from web.php and api.php)
            'customers.index',
            'customers.search',
            'customers.tier-options',
            'customers.store',
            'customers.show',
            'customers.update',
            
            // Discounts (from web.php)
            'products.discounts.index',
            'products.discounts.store',
            'products.discounts.update',
            'products.discounts.destroy',
            
            // Mandatory Discounts (from web.php)
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            
            // Loyalty Program (from web.php and api.php)
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.customers.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.store',
            'loyalty.tiers.show',
            'loyalty.tiers.update',
            'loyalty.tiers.destroy',
            
            // Inventory Management (from web.php and api.php)
            'inventory.index',
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
            'inventory.search.locations',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',
            
            // Void Logs (from web.php)
            'voids.index',
            
            // Terminal Setup (from web.php)
            'setup.terminal',
            
            // Role Management (from web.php - super user only)
            'roles.index',
            'roles.create',
            'roles.store',
            'roles.show',
            'roles.edit',
            'roles.update',
            'roles.destroy',
            'roles.permission-matrix',
            'roles.permissions',
            
            // Permission Management (from web.php - super user only)
            'permissions.index',
            'permissions.store',
            'permissions.show',
            'permissions.update',
            'permissions.deactivate',
            'permissions.activate',
            'permissions.bulk-deactivate',
            'permissions.grouped',
            
            // Dashboard API (from api.php)
            'dashboard.api.sales-chart',
            
            // User Management API (from api.php)
            'api.users.index',
            'api.users.store',
            'api.users.show',
            'api.users.update',
            'api.users.destroy',
            'users.roles',
            'users.toggle-status',
            
            // Inventory API (from api.php)
            'inventory.api.products',
            'inventory.api.movements',
            'inventory.api.low-stock',
            'inventory.api.valuation',
            'inventory.api.receive',
            'inventory.api.transfer',
            'inventory.api.search.products',
            'inventory.api.search.movements',
            'inventory.api.locations.summary',
            'api.locations.index',
            'api.locations.store',
            'api.locations.show',
            'api.locations.update',
            'api.locations.destroy',
            'inventory.api.search.locations',
            'api.locations.set-default',
            'api.locations.toggle-status',
            'inventory.api.adjustments.index',
            'inventory.api.adjustments.store',
            'inventory.api.adjustments.show',
            'inventory.api.adjustments.update',
            'inventory.api.adjustments.submit',
            'inventory.api.adjustments.approve',
            'inventory.api.adjustments.reject',
            'inventory.api.adjustment-products',
            
            // Orders API (from api.php)
            'orders.view',
            'orders.recent-pending',
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
            'dashboard.index',
            'dashboard.api.sales-chart',
            
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
            'api.users.index',
            'api.users.store',
            'api.users.show',
            'api.users.update',
            'api.users.destroy',
            'users.roles',
            'users.toggle-status',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.syncDraft',
            'sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.assignCustomer',
            'sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            'products.store',
            'products.update',
            'products.destroy',
            
            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            'categories.destroy',
            
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
            
            // Mandatory Discounts
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            
            // Loyalty Program
            'loyalty.index',
            'loyalty.stats',
            'loyalty.customers',
            'loyalty.analytics',
            'loyalty.customers.adjust-points',
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
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.store',
            'inventory.locations.show',
            'inventory.locations.update',
            'inventory.locations.destroy',
            'inventory.search.locations',
            'inventory.locations.set-default',
            'inventory.locations.toggle-status',
            'inventory.adjustments.index',
            'inventory.adjustments.store',
            'inventory.adjustments.show',
            'inventory.adjustments.update',
            'inventory.adjustments.submit',
            'inventory.adjustments.approve',
            'inventory.adjustments.reject',
            'inventory.adjustment-products',
            'inventory.api.products',
            'inventory.api.movements',
            'inventory.api.low-stock',
            'inventory.api.valuation',
            'inventory.api.receive',
            'inventory.api.transfer',
            'inventory.api.search.products',
            'inventory.api.search.movements',
            'inventory.api.locations.summary',
            'api.locations.index',
            'api.locations.store',
            'api.locations.show',
            'api.locations.update',
            'api.locations.destroy',
            'inventory.api.search.locations',
            'api.locations.set-default',
            'api.locations.toggle-status',
            'inventory.api.adjustments.index',
            'inventory.api.adjustments.store',
            'inventory.api.adjustments.show',
            'inventory.api.adjustments.update',
            'inventory.api.adjustments.submit',
            'inventory.api.adjustments.approve',
            'inventory.api.adjustments.reject',
            'inventory.api.adjustment-products',
            
            // Void Logs
            'voids.index',
            
            // Terminal Setup
            'setup.terminal',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
            
            // Permission Management (admin can view only)
            'permissions.index',
            'permissions.show',
            'permissions.grouped',
        ]);
        
        // Manager - Operational permissions
        $manager = Role::findOrCreate('manager', 'web');
        $manager->update(['level' => 3, 'description' => 'Operational management with reporting and staff oversight']);
        $manager->syncPermissions([
            // Dashboard
            'dashboard.index',
            'dashboard.api.sales-chart',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.syncDraft',
            'sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.assignCustomer',
            'sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            'products.store',
            'products.update',
            
            // Categories
            'categories.index',
            'categories.store',
            'categories.update',
            
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
            'loyalty.customers.adjust-points',
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
            'inventory.api.products',
            'inventory.api.movements',
            'inventory.api.low-stock',
            'inventory.api.valuation',
            'inventory.api.receive',
            'inventory.api.transfer',
            'inventory.api.search.products',
            'inventory.api.search.movements',
            'inventory.api.locations.summary',
            'api.locations.index',
            'api.locations.show',
            'inventory.api.search.locations',
            'inventory.api.adjustments.index',
            'inventory.api.adjustments.show',
            'inventory.api.adjustments.submit',
            'inventory.api.adjustments.approve',
            'inventory.api.adjustments.reject',
            'inventory.api.adjustment-products',
            
            // Void Logs
            'voids.index',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
        ]);
        
        // Supervisor - Shift supervision permissions
        $supervisor = Role::findOrCreate('supervisor', 'web');
        $supervisor->update(['level' => 4, 'description' => 'Shift supervision with limited management capabilities']);
        $supervisor->syncPermissions([
            // Dashboard
            'dashboard.index',
            'dashboard.api.sales-chart',
            
            // User Management (view only)
            'users.index',
            'users.show',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.syncDraft',
            'sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.assignCustomer',
            'sales.processLoyalty',
            'sales.discounts.order.apply',
            'sales.discounts.order.remove',
            'sales.items.discount.apply',
            'sales.items.discount.remove',
            
            // Products
            'products.index',
            'products.store',
            'products.update',
            
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
            'loyalty.customers.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',
            
            // Inventory Management
            'inventory.index',
            'inventory.products',
            'inventory.movements',
            'inventory.low-stock',
            'inventory.search.products',
            'inventory.search.movements',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',
            'inventory.api.products',
            'inventory.api.movements',
            'inventory.api.low-stock',
            'inventory.api.search.products',
            'inventory.api.search.movements',
            'inventory.api.locations.summary',
            'api.locations.index',
            'api.locations.show',
            'inventory.api.search.locations',
            
            // Void Logs
            'voids.index',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
        ]);
        
        // Cashier - Basic sales permissions
        $cashier = Role::findOrCreate('cashier', 'web');
        $cashier->update(['level' => 5, 'description' => 'Front-line operations with sales processing capabilities']);
        $cashier->syncPermissions([
            // Dashboard
            'dashboard.index',
            'dashboard.api.sales-chart',
            
            // Sales
            'sales.index',
            'sales.products',
            'sales.drafts.store',
            'sales.items.void',
            'sales.payment.store',
            'sales.syncDraft',
            'sales.syncDraftImmediate',
            'sales.find-sale-item',
            'sales.assignCustomer',
            'sales.processLoyalty',
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
            'loyalty.customers.adjust-points',
            'loyalty.tiers.index',
            'loyalty.tiers.show',
            
            // Inventory Management (view only)
            'inventory.index',
            'inventory.products',
            'inventory.search.products',
            'inventory.locations.summary',
            'inventory.locations.index',
            'inventory.locations.show',
            'inventory.search.locations',
            'inventory.api.products',
            'inventory.api.search.products',
            'inventory.api.locations.summary',
            'api.locations.index',
            'api.locations.show',
            'inventory.api.search.locations',
            
            // Orders
            'orders.view',
            'orders.recent-pending',
        ]);

        $this->command->info('Roles and permissions created successfully!');
    }
}