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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions
        
        // Admin - Most permissions except system settings
        $admin = Role::firstOrCreate(['name' => 'admin']);
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
        ]);
        
        // Manager - Operational permissions
        $manager = Role::firstOrCreate(['name' => 'manager']);
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
        $supervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $supervisor->syncPermissions([
            'dashboard.view',
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
        $cashier = Role::firstOrCreate(['name' => 'cashier']);
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