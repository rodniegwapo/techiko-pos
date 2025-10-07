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

        // Create permissions
        $permissions = [
            // Dashboard
            'dashboard.view',
            
            // User Management
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            
            // Sales
            'sales.view',
            'sales.create',
            'sales.void',
            
            // Products
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            
            // Categories
            'categories.view',
            'categories.create',
            'categories.edit',
            'categories.delete',
            
            // Customers
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            
            // Discounts
            'discounts.view',
            'discounts.create',
            'discounts.edit',
            'discounts.delete',
            'discounts.apply',

            // Mandatory Discounts (route-aligned permissions)
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            
            // Reports
            'reports.view',
            'reports.export',
            
            // Loyalty Program
            'loyalty.view',
            'loyalty.manage',
            'loyalty.adjust_points',
            'loyalty.tiers_manage',
            'loyalty.customers_manage',
            'loyalty.points_adjust',
            'loyalty.reports_view',
            
            // Inventory Management
            'inventory.view',
            'inventory.dashboard',
            'inventory.products',
            'inventory.movements',
            'inventory.adjustments',
            'inventory.locations',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.low_stock',
            
            // System Settings
            'settings.view',
            'settings.edit',
            
            // Void Logs
            'void_logs.view',
            
            // Role Management
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            
            // Permission Management
            'permissions.view',
            'permissions.create',
            'permissions.edit',
            'permissions.delete',
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
            'dashboard.view',
            'users.view',
            'users.create',
            'users.edit',
            'sales.view',
            'sales.create',
            'sales.void',
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            'categories.view',
            'categories.create',
            'categories.edit',
            'categories.delete',
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            'discounts.view',
            'discounts.create',
            'discounts.edit',
            'discounts.delete',
            'discounts.apply',
            // Mandatory Discounts (admin full access)
            'mandatory-discounts.index',
            'mandatory-discounts.store',
            'mandatory-discounts.update',
            'mandatory-discounts.destroy',
            'reports.view',
            'reports.export',
            'loyalty.view',
            'loyalty.manage',
            'loyalty.adjust_points',
            'loyalty.tiers_manage',
            'loyalty.customers_manage',
            'loyalty.points_adjust',
            'loyalty.reports_view',
            'inventory.view',
            'inventory.dashboard',
            'inventory.products',
            'inventory.movements',
            'inventory.adjustments',
            'inventory.locations',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.low_stock',
            'void_logs.view',
            // Permission Management (admin can view only)
            'permissions.view',
        ]);
        
        // Manager - Operational permissions
        $manager = Role::findOrCreate('manager', 'web');
        $manager->update(['level' => 3, 'description' => 'Operational management with reporting and staff oversight']);
        $manager->syncPermissions([
            'dashboard.view',
            'sales.view',
            'sales.create',
            'sales.void',
            'products.view',
            'products.create',
            'products.edit',
            'categories.view',
            'categories.create',
            'categories.edit',
            'customers.view',
            'customers.create',
            'customers.edit',
            'discounts.view',
            'discounts.apply',
            // Mandatory Discounts (manager read-only)
            'mandatory-discounts.index',
            'reports.view',
            'loyalty.view',
            'loyalty.adjust_points',
            'loyalty.customers_manage',
            'loyalty.points_adjust',
            'loyalty.reports_view',
            'inventory.view',
            'inventory.dashboard',
            'inventory.products',
            'inventory.movements',
            'inventory.adjustments',
            'inventory.locations',
            'inventory.valuation',
            'inventory.receive',
            'inventory.transfer',
            'inventory.low_stock',
            'void_logs.view',
        ]);
        
        // Supervisor - Shift supervision permissions
        $supervisor = Role::findOrCreate('supervisor', 'web');
        $supervisor->update(['level' => 4, 'description' => 'Shift supervision with limited management capabilities']);
        $supervisor->syncPermissions([
            'dashboard.view',
            'users.view',
            'sales.view',
            'sales.create',
            'sales.void',
            'products.view',
            'products.create',
            'products.edit',
            'categories.view',
            'customers.view',
            'customers.create',
            'customers.edit',
            'discounts.view',
            'discounts.apply',
            // Mandatory Discounts (optional: view)
            'mandatory-discounts.index',
            'reports.view',
            'loyalty.view',
            'loyalty.customers_manage',
            'loyalty.points_adjust',
            'inventory.view',
            'inventory.products',
            'inventory.movements',
            'inventory.low_stock',
            'void_logs.view',
        ]);
        
        // Cashier - Basic sales permissions
        $cashier = Role::findOrCreate('cashier', 'web');
        $cashier->update(['level' => 5, 'description' => 'Front-line operations with sales processing capabilities']);
        $cashier->syncPermissions([
            'dashboard.view',
            'sales.view',
            'sales.create',
            'sales.void',
            'products.view',
            'customers.view',
            'customers.create',
            'discounts.view',
            'discounts.apply',
            'loyalty.view',
            'loyalty.customers_manage',
            'inventory.view',
            'inventory.products',
        ]);

        $this->command->info('Roles and permissions created successfully!');
    }
}