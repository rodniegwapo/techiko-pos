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
    
    // Check if user is super user (has all permissions)
    const isSuperUser = computed(() => currentUser.value?.is_super_user || false)
    
    // Check if user can manage users
    const canManageUsers = computed(() => isSuperUser.value || hasAnyRole(['admin', 'manager', 'supervisor']))
    
    // Check if user can manage roles
    const canManageRoles = computed(() => isSuperUser.value)
    
    // Check if user can view categories
    const canViewCategories = computed(() => isSuperUser.value || hasPermission('categories.view'))
    
    // Check if user can manage categories
    const canManageCategories = computed(() => isSuperUser.value || hasAnyPermission(['categories.create', 'categories.edit', 'categories.delete']))
    
    // Check if user can view products
    const canViewProducts = computed(() => isSuperUser.value || hasPermission('products.view'))
    
    // Check if user can manage products
    const canManageProducts = computed(() => isSuperUser.value || hasAnyPermission(['products.create', 'products.edit', 'products.delete']))
    
    // Check if user can view sales
    const canViewSales = computed(() => isSuperUser.value || hasPermission('sales.view'))
    
    // Check if user can manage sales
    const canManageSales = computed(() => isSuperUser.value || hasAnyPermission(['sales.create', 'sales.edit', 'sales.delete']))
    
    // Check if user can view inventory
    const canViewInventory = computed(() => isSuperUser.value || hasPermission('inventory.view'))
    
    // Check if user can manage inventory
    const canManageInventory = computed(() => isSuperUser.value || hasAnyPermission(['inventory.receive', 'inventory.transfer', 'inventory.adjustments', 'inventory.locations']))
    
    // Check if user can view loyalty
    const canViewLoyalty = computed(() => isSuperUser.value || hasPermission('loyalty.view'))
    
    // Check if user can manage loyalty
    const canManageLoyalty = computed(() => isSuperUser.value || hasAnyPermission(['loyalty.manage', 'loyalty.adjust_points']))
    
    // Check if user can view customers
    const canViewCustomers = computed(() => isSuperUser.value || hasPermission('customers.view'))
    
    // Check if user can manage customers
    const canManageCustomers = computed(() => isSuperUser.value || hasPermission('customers.manage'))
    
    // Check if user can view dashboard
    const canViewDashboard = computed(() => isSuperUser.value || hasPermission('dashboard.view'))
    
    // Check if user can manage terminal
    const canManageTerminal = computed(() => isSuperUser.value || hasPermission('terminal.manage'))
    
    // Check if user can view void logs
    const canViewVoidLogs = computed(() => isSuperUser.value || hasPermission('voids.view'))
    
    return {
        currentUser,
        userRoles,
        userPermissions,
        hasRole,
        hasAnyRole,
        hasPermission,
        hasAnyPermission,
        isSuperUser,
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
