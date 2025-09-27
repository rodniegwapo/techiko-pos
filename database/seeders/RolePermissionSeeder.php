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
            
            // System Settings
            'settings.view',
            'settings.edit',
            
            // Void Logs
            'void_logs.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions
        
        // Super Admin - Full access
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin']);
        $superAdmin->syncPermissions(Permission::all());
        
        // Admin - Most permissions except system settings
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $admin->syncPermissions([
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
            'void_logs.view',
        ]);
        
        // Manager - Operational permissions
        $manager = Role::firstOrCreate(['name' => 'Manager']);
        $manager->syncPermissions([
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
            'void_logs.view',
        ]);
        
        // Cashier - Basic sales permissions
        $cashier = Role::firstOrCreate(['name' => 'Cashier']);
        $cashier->syncPermissions([
            'sales.view',
            'sales.create',
            'products.view',
            'customers.view',
            'customers.create',
            'discounts.view',
            'discounts.apply',
            'loyalty.view',
        ]);

        $this->command->info('Roles and permissions created successfully!');
    }
}