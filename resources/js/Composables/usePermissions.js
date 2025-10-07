import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function usePermissions() {
    const page = usePage()

    // Current user and permissions from backend
    const currentUser = computed(() => page.props.auth?.user?.data)
    const userPermissions = computed(() => {
        if (!currentUser.value?.permissions) return []
        return currentUser.value.permissions.map(p => p.name)
    })

    // Roles (kept for compatibility)
    const userRoles = computed(() => {
        if (!currentUser.value?.roles) return []
        return currentUser.value.roles.map(role => role.name.toLowerCase())
    })

    // Helpers
    const hasPermission = (permissionName) => userPermissions.value.includes(permissionName)
    const hasAnyPermission = (permissionNames) => {
        const permissions = Array.isArray(permissionNames) ? permissionNames : [permissionNames]
        return permissions.some(permission => hasPermission(permission))
    }
    const hasRole = (roleName) => userRoles.value.includes(roleName.toLowerCase())
    const hasAnyRole = (roleNames) => {
        const roles = Array.isArray(roleNames) ? roleNames : [roleNames]
        return roles.some(role => hasRole(role))
    }

    // Super user bypass
    const isSuperUser = computed(() => currentUser.value?.is_super_user || false)

    // Permission-driven gates
    const canViewUsers = computed(() => isSuperUser.value || hasPermission('users.view'))
    const canManageUsers = computed(() => isSuperUser.value || hasAnyPermission(['users.create','users.edit','users.delete']))
    const canManageRoles = computed(() => isSuperUser.value || hasPermission('roles.view'))

    const canViewCategories = computed(() => isSuperUser.value || hasPermission('categories.view'))
    const canManageCategories = computed(() => isSuperUser.value || hasAnyPermission(['categories.create', 'categories.edit', 'categories.delete']))

    const canViewProducts = computed(() => isSuperUser.value || hasPermission('products.view'))
    const canManageProducts = computed(() => isSuperUser.value || hasAnyPermission(['products.create', 'products.edit', 'products.delete']))

    const canViewSales = computed(() => isSuperUser.value || hasPermission('sales.view'))
    const canManageSales = computed(() => isSuperUser.value || hasAnyPermission(['sales.create', 'sales.edit', 'sales.delete']))

    const canViewInventory = computed(() => isSuperUser.value || hasPermission('inventory.view'))
    const canManageInventory = computed(() => isSuperUser.value || hasAnyPermission(['inventory.receive', 'inventory.transfer', 'inventory.adjustments', 'inventory.locations']))

    const canViewLoyalty = computed(() => isSuperUser.value || hasPermission('loyalty.view'))
    const canManageLoyalty = computed(() => isSuperUser.value || hasAnyPermission(['loyalty.manage', 'loyalty.adjust_points']))

    const canViewCustomers = computed(() => isSuperUser.value || hasPermission('customers.view'))
    const canManageCustomers = computed(() => isSuperUser.value || hasPermission('customers.manage'))

    const canViewDashboard = computed(() => isSuperUser.value || hasPermission('dashboard.view'))
    const canManageTerminal = computed(() => isSuperUser.value || hasPermission('terminal.manage'))

    // Fix key to match seeder: 'void_logs.view'
    const canViewVoidLogs = computed(() => isSuperUser.value || hasPermission('void_logs.view'))

    return {
        currentUser,
        userRoles,
        userPermissions,
        hasRole,
        hasAnyRole,
        hasPermission,
        hasAnyPermission,
        isSuperUser,
        canViewUsers,
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
