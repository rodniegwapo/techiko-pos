import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function usePermissions() {
    const page = usePage()

    // Current user and permissions from backend (names align with route names in seeder)
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

    // Super user bypass
    const isSuperUser = computed(() => currentUser.value?.is_super_user || false)

    // ==============================
    // Core helpers (align with menu)
    // ==============================
    const hasPerm = (permissionName) => userPermissions.value.includes(permissionName)

    // Prefix-like check: if you pass "inventory.locations" it will match
    // any permission that starts with that base (e.g., inventory.locations.index, .store, etc.)
    const hasPermLike = (permissionBase) => {
        const base = String(permissionBase).split('.')[0] // module base like 'inventory'
        return userPermissions.value.some(p => p === permissionBase || p.startsWith(`${permissionBase}.`) || p.startsWith(`${base}.`))
    }

    // Exact or prefix access for a given route name (e.g., 'inventory.index')
    const canAccessRoute = (routeName) => {
        if (isSuperUser.value) return true
        return hasPerm(routeName) || hasPermLike(routeName)
    }

    // Convenience: checks exact or prefix for any of provided names
    const hasAny = (names) => {
        const list = Array.isArray(names) ? names : [names]
        return list.some(n => hasPerm(n) || hasPermLike(n))
    }

    // ==============================
    // High-level gates (back-compat)
    // Map to route-name-based permissions from seeder
    // ==============================
    const canViewDashboard = computed(() => canAccessRoute('dashboard'))
    const canManageTerminal = computed(() => canAccessRoute('setup.terminal'))

    // Users
    const canViewUsers = computed(() => canAccessRoute('users.index'))
    const canManageUsers = computed(() => hasAny(['users.store', 'users.update', 'users.destroy']))
    const canManageRoles = computed(() => hasAny('roles')) // any roles.*

    // Categories
    const canViewCategories = computed(() => canAccessRoute('categories.index'))
    const canManageCategories = computed(() => hasAny(['categories.store', 'categories.update', 'categories.destroy']))

    // Products
    const canViewProducts = computed(() => canAccessRoute('products.index'))
    const canManageProducts = computed(() => hasAny(['products.store', 'products.update', 'products.destroy']))

    // Sales
    const canViewSales = computed(() => canAccessRoute('sales.index'))
    const canManageSales = computed(() => hasAny([
        'sales.drafts.store',
        'sales.items.void',
        'sales.payment.store',
        'sales.items.discount.apply',
        'sales.discounts.order.apply'
    ]))

    // Inventory
    const canViewInventory = computed(() => canAccessRoute('inventory.index'))
    const canManageInventory = computed(() => hasAny([
        'inventory.receive',
        'inventory.transfer',
        'inventory.adjustments', // prefix matches .index/.store/.update etc
        'inventory.locations'    // prefix
    ]))

    // Loyalty
    const canViewLoyalty = computed(() => canAccessRoute('loyalty.index'))
    const canManageLoyalty = computed(() => hasAny([
        'loyalty.adjust-points',
        'loyalty.tiers' // prefix for tiers CRUD
    ]))

    // Customers
    const canViewCustomers = computed(() => canAccessRoute('customers.index'))
    const canManageCustomers = computed(() => hasAny(['customers.store', 'customers.update']))

    // Void Logs (route name is 'voids.index')
    const canViewVoidLogs = computed(() => canAccessRoute('voids.index'))

    return {
        currentUser,
        userRoles,
        userPermissions,
        isSuperUser,

        // core helpers
        hasPerm,
        hasPermLike,
        canAccessRoute,
        hasAny,

        // high-level gates (back-compat)
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
