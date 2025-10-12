<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            [
                'name' => 'dashboard',
                'display_name' => 'Dashboard',
                'icon' => 'dashboard',
                'description' => 'Main dashboard and overview',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'users',
                'display_name' => 'Users',
                'icon' => 'users',
                'description' => 'User management and administration',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'roles',
                'display_name' => 'Roles',
                'icon' => 'shield',
                'description' => 'Role and permission management',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'permissions',
                'display_name' => 'Permissions',
                'icon' => 'key',
                'description' => 'Permission management',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => 'products',
                'display_name' => 'Products',
                'icon' => 'package',
                'description' => 'Product catalog management',
                'sort_order' => 5,
                'is_active' => true,
            ],
            [
                'name' => 'categories',
                'display_name' => 'Categories',
                'icon' => 'folder',
                'description' => 'Product category management',
                'sort_order' => 6,
                'is_active' => true,
            ],
            [
                'name' => 'customers',
                'display_name' => 'Customers',
                'icon' => 'user-group',
                'description' => 'Customer management',
                'sort_order' => 7,
                'is_active' => true,
            ],
            [
                'name' => 'sales',
                'display_name' => 'Sales',
                'icon' => 'shopping-cart',
                'description' => 'Sales and transaction management',
                'sort_order' => 8,
                'is_active' => true,
            ],
            [
                'name' => 'inventory',
                'display_name' => 'Inventory',
                'icon' => 'warehouse',
                'description' => 'Inventory and stock management',
                'sort_order' => 9,
                'is_active' => true,
            ],
            [
                'name' => 'loyalty',
                'display_name' => 'Loyalty',
                'icon' => 'star',
                'description' => 'Loyalty program management',
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'discounts',
                'display_name' => 'Discounts',
                'icon' => 'tag',
                'description' => 'Discount and promotion management',
                'sort_order' => 11,
                'is_active' => true,
            ],
            [
                'name' => 'mandatory-discounts',
                'display_name' => 'Mandatory Discounts',
                'icon' => 'tag-off',
                'description' => 'Mandatory discount management',
                'sort_order' => 12,
                'is_active' => true,
            ],
            [
                'name' => 'voids',
                'display_name' => 'Void Logs',
                'icon' => 'x-circle',
                'description' => 'Void transaction logs',
                'sort_order' => 13,
                'is_active' => true,
            ],
            [
                'name' => 'orders',
                'display_name' => 'Orders',
                'icon' => 'clipboard-list',
                'description' => 'Order management',
                'sort_order' => 14,
                'is_active' => true,
            ],
            [
                'name' => 'supervisors',
                'display_name' => 'Supervisors',
                'icon' => 'user-check',
                'description' => 'Supervisor management',
                'sort_order' => 15,
                'is_active' => true,
            ],
            [
                'name' => 'setup',
                'display_name' => 'Setup',
                'icon' => 'settings',
                'description' => 'System setup and configuration',
                'sort_order' => 16,
                'is_active' => true,
            ],
        ];

        foreach ($modules as $module) {
            DB::table('permission_modules')->updateOrInsert(
                ['name' => $module['name']],
                $module
            );
        }
    }
}