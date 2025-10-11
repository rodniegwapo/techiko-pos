<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PopulatePermissionDisplayNamesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all permissions that don't have proper display names
        $permissions = Permission::all();

        $displayNameMap = [
            // Users
            'users.index' => 'View Users',
            'users.create' => 'Create User',
            'users.store' => 'Create User',
            'users.edit' => 'Edit User',
            'users.update' => 'Update User',
            'users.show' => 'View User',
            'users.destroy' => 'Delete User',
            'users.hierarchy' => 'View User Hierarchy',
            
            // Roles
            'roles.index' => 'View Roles',
            'roles.create' => 'Create Role',
            'roles.store' => 'Create Role',
            'roles.edit' => 'Edit Role',
            'roles.update' => 'Update Role',
            'roles.show' => 'View Role',
            'roles.destroy' => 'Delete Role',
            
            // Permissions
            'permissions.index' => 'View Permissions',
            'permissions.create' => 'Create Permission',
            'permissions.store' => 'Create Permission',
            'permissions.edit' => 'Edit Permission',
            'permissions.update' => 'Update Permission',
            'permissions.show' => 'View Permission',
            'permissions.destroy' => 'Delete Permission',
            
            // Products
            'products.index' => 'View Products',
            'products.create' => 'Create Product',
            'products.store' => 'Create Product',
            'products.edit' => 'Edit Product',
            'products.update' => 'Update Product',
            'products.show' => 'View Product',
            'products.destroy' => 'Delete Product',
            
            // Categories
            'categories.index' => 'View Categories',
            'categories.create' => 'Create Category',
            'categories.store' => 'Create Category',
            'categories.edit' => 'Edit Category',
            'categories.update' => 'Update Category',
            'categories.show' => 'View Category',
            'categories.destroy' => 'Delete Category',
            
            // Inventory
            'inventory.index' => 'View Inventory',
            'inventory.dashboard' => 'Inventory Dashboard',
            'inventory.locations.index' => 'View Locations',
            'inventory.locations.create' => 'Create Location',
            'inventory.locations.store' => 'Create Location',
            'inventory.locations.edit' => 'Edit Location',
            'inventory.locations.update' => 'Update Location',
            'inventory.locations.show' => 'View Location',
            'inventory.locations.destroy' => 'Delete Location',
            'inventory.locations.set-default' => 'Set Default Location',
            'inventory.locations.toggle-status' => 'Toggle Location Status',
            
            'inventory.products.index' => 'View Product Inventory',
            'inventory.products.create' => 'Create Product Inventory',
            'inventory.products.store' => 'Create Product Inventory',
            'inventory.products.edit' => 'Edit Product Inventory',
            'inventory.products.update' => 'Update Product Inventory',
            'inventory.products.show' => 'View Product Inventory',
            'inventory.products.destroy' => 'Delete Product Inventory',
            
            'inventory.movements.index' => 'View Inventory Movements',
            'inventory.movements.create' => 'Create Inventory Movement',
            'inventory.movements.store' => 'Create Inventory Movement',
            'inventory.movements.edit' => 'Edit Inventory Movement',
            'inventory.movements.update' => 'Update Inventory Movement',
            'inventory.movements.show' => 'View Inventory Movement',
            'inventory.movements.destroy' => 'Delete Inventory Movement',
            
            'inventory.adjustments.index' => 'View Stock Adjustments',
            'inventory.adjustments.create' => 'Create Stock Adjustment',
            'inventory.adjustments.store' => 'Create Stock Adjustment',
            'inventory.adjustments.edit' => 'Edit Stock Adjustment',
            'inventory.adjustments.update' => 'Update Stock Adjustment',
            'inventory.adjustments.show' => 'View Stock Adjustment',
            'inventory.adjustments.destroy' => 'Delete Stock Adjustment',
            'inventory.adjustments.approve' => 'Approve Stock Adjustment',
            'inventory.adjustments.reject' => 'Reject Stock Adjustment',
            
            'inventory.valuation.index' => 'View Inventory Valuation',
            'inventory.valuation.export' => 'Export Inventory Valuation',
            
            'inventory.low-stock.index' => 'View Low Stock Report',
            'inventory.low-stock.export' => 'Export Low Stock Report',
            
            // Sales
            'sales.index' => 'View Sales',
            'sales.create' => 'Create Sale',
            'sales.store' => 'Create Sale',
            'sales.edit' => 'Edit Sale',
            'sales.update' => 'Update Sale',
            'sales.show' => 'View Sale',
            'sales.destroy' => 'Delete Sale',
            'sales.dashboard' => 'Sales Dashboard',
            'sales.export' => 'Export Sales',
            
            // Customers
            'customers.index' => 'View Customers',
            'customers.create' => 'Create Customer',
            'customers.store' => 'Create Customer',
            'customers.edit' => 'Edit Customer',
            'customers.update' => 'Update Customer',
            'customers.show' => 'View Customer',
            'customers.destroy' => 'Delete Customer',
            
            // Loyalty
            'loyalty.index' => 'View Loyalty',
            'loyalty.tiers.index' => 'View Loyalty Tiers',
            'loyalty.tiers.create' => 'Create Loyalty Tier',
            'loyalty.tiers.store' => 'Create Loyalty Tier',
            'loyalty.tiers.edit' => 'Edit Loyalty Tier',
            'loyalty.tiers.update' => 'Update Loyalty Tier',
            'loyalty.tiers.show' => 'View Loyalty Tier',
            'loyalty.tiers.destroy' => 'Delete Loyalty Tier',
            
            // Discounts
            'discounts.index' => 'View Discounts',
            'discounts.create' => 'Create Discount',
            'discounts.store' => 'Create Discount',
            'discounts.edit' => 'Edit Discount',
            'discounts.update' => 'Update Discount',
            'discounts.show' => 'View Discount',
            'discounts.destroy' => 'Delete Discount',
            
            // Reports
            'reports.index' => 'View Reports',
            'reports.sales' => 'Sales Reports',
            'reports.inventory' => 'Inventory Reports',
            'reports.customers' => 'Customer Reports',
            'reports.export' => 'Export Reports',
            
            // Settings
            'settings.index' => 'View Settings',
            'settings.update' => 'Update Settings',
        ];

        $updatedCount = 0;
        $usedNames = [];
        
        foreach ($permissions as $permission) {
            $routeName = $permission->route_name ?? $permission->name;
            
            // If we have a display name mapping for this route, use it
            if (isset($displayNameMap[$routeName])) {
                $newDisplayName = $displayNameMap[$routeName];
            } else {
                // Generate a display name from the route name
                $newDisplayName = $this->generateDisplayName($routeName);
            }
            
            // Check if this display name is already used
            $counter = 1;
            $originalDisplayName = $newDisplayName;
            while (in_array($newDisplayName, $usedNames)) {
                $newDisplayName = $originalDisplayName . " ({$counter})";
                $counter++;
            }
            
            // Only update if the name has changed
            if ($newDisplayName !== $permission->name) {
                try {
                    $permission->update([
                        'name' => $newDisplayName
                    ]);
                    $usedNames[] = $newDisplayName;
                    $updatedCount++;
                } catch (\Exception $e) {
                    $this->command->warn("Failed to update permission {$permission->id}: {$e->getMessage()}");
                }
            } else {
                $usedNames[] = $newDisplayName;
            }
        }

        $this->command->info("Updated {$updatedCount} permissions with display names.");
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

            return $actionLabels[$action] ?? ucfirst($action);
        }
        
        return ucfirst($routeName);
    }
}