import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function usePermissions() {
    const page = usePage()
    
    // Get current user
    const currentUser = computed(() => page.props.auth?.user?.data)
    
    // Get user roles
    const userRoles = computed(() => {
        if (!currentUser.value?.roles) return []
        return currentUser.value.roles.map(role => role.name.toLowerCase())
    })
    
    // Get user permissions
    const userPermissions = computed(() => {
        if (!currentUser.value?.permissions) return []
        return currentUser.value.permissions.map(permission => permission.name)
    })
    
    // Check if user has specific role
    const hasRole = (roleName) => {
        return userRoles.value.includes(roleName.toLowerCase())
    }
    
    // Check if user has any of the specified roles
    const hasAnyRole = (roleNames) => {
        const roles = Array.isArray(roleNames) ? roleNames : [roleNames]
        return roles.some(role => hasRole(role))
    }
    
    // Check if user has specific permission
    const hasPermission = (permissionName) => {
        return userPermissions.value.includes(permissionName)
    }
    
    // Check if user has any of the specified permissions
    const hasAnyPermission = (permissionNames) => {
        const permissions = Array.isArray(permissionNames) ? permissionNames : [permissionNames]
        return permissions.some(permission => hasPermission(permission))
    }
    
    // Check if user is super admin (has all permissions)
    const isSuperAdmin = computed(() => hasRole('super admin'))
    
    // Check if user can manage users
    const canManageUsers = computed(() => hasAnyRole(['super admin', 'admin', 'manager', 'supervisor']))
    
    // Check if user can manage roles
    const canManageRoles = computed(() => hasRole('super admin'))
    
    // Check if user can view categories
    const canViewCategories = computed(() => hasPermission('categories.view') || isSuperAdmin.value)
    
    // Check if user can manage categories
    const canManageCategories = computed(() => hasAnyPermission(['categories.create', 'categories.edit', 'categories.delete']) || isSuperAdmin.value)
    
    // Check if user can view products
    const canViewProducts = computed(() => hasPermission('products.view') || isSuperAdmin.value)
    
    // Check if user can manage products
    const canManageProducts = computed(() => hasAnyPermission(['products.create', 'products.edit', 'products.delete']) || isSuperAdmin.value)
    
    // Check if user can view sales
    const canViewSales = computed(() => hasPermission('sales.view') || isSuperAdmin.value)
    
    // Check if user can manage sales
    const canManageSales = computed(() => hasAnyPermission(['sales.create', 'sales.edit', 'sales.delete']) || isSuperAdmin.value)
    
    // Check if user can view inventory
    const canViewInventory = computed(() => hasPermission('inventory.view') || isSuperAdmin.value)
    
    // Check if user can manage inventory
    const canManageInventory = computed(() => hasAnyPermission(['inventory.receive', 'inventory.transfer', 'inventory.adjustments', 'inventory.locations']) || isSuperAdmin.value)
    
    // Check if user can view loyalty
    const canViewLoyalty = computed(() => hasPermission('loyalty.view') || isSuperAdmin.value)
    
    // Check if user can manage loyalty
    const canManageLoyalty = computed(() => hasAnyPermission(['loyalty.manage', 'loyalty.adjust_points']) || isSuperAdmin.value)
    
    // Check if user can view customers
    const canViewCustomers = computed(() => hasPermission('customers.view') || isSuperAdmin.value)
    
    // Check if user can manage customers
    const canManageCustomers = computed(() => hasPermission('customers.manage') || isSuperAdmin.value)
    
    // Check if user can view dashboard
    const canViewDashboard = computed(() => hasPermission('dashboard.view') || isSuperAdmin.value)
    
    // Check if user can manage terminal
    const canManageTerminal = computed(() => hasPermission('terminal.manage') || isSuperAdmin.value)
    
    // Check if user can view void logs
    const canViewVoidLogs = computed(() => hasPermission('voids.view') || isSuperAdmin.value)
    
    return {
        currentUser,
        userRoles,
        userPermissions,
        hasRole,
        hasAnyRole,
        hasPermission,
        hasAnyPermission,
        isSuperAdmin,
        canManageUsers,
        canManageRoles,
        canViewCategories,
        canManageCategories,
        canViewProducts,
        canManageProducts,
        canViewSales,
        canManageSales,
        canViewInventory,
        canManageInventory,
        canViewLoyalty,
        canManageLoyalty,
        canViewCustomers,
        canManageCustomers,
        canViewDashboard,
        canManageTerminal,
        canViewVoidLogs
    }
}
