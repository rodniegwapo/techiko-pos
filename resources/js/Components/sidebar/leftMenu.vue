<script setup>
import { ref, computed, watch, onMounted } from "vue";
import {
    IconDashboard,
    IconCategory,
    IconUsers,
    IconHeartHandshake,
    IconHistory,
    IconGift,
    IconUserCog,
    IconPackage,
    IconShield,
    IconUserCheck,
    IconWorld,
    IconKey,
} from "@tabler/icons-vue";
import { router, usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const page = usePage();
const { hasPermission } = usePermissionsV2();
const { selectedKeys, openKeys } = useGlobalVariables();

// Ensure selectedKeys and openKeys are arrays
if (!Array.isArray(selectedKeys.value)) {
    selectedKeys.value = [];
}
if (!Array.isArray(openKeys.value)) {
    openKeys.value = [];
}

// Initialize menu state on component mount
onMounted(() => {
    // Ensure menu state is properly initialized
    if (!Array.isArray(selectedKeys.value)) {
        selectedKeys.value = [];
    }
    if (!Array.isArray(openKeys.value)) {
        openKeys.value = [];
    }
    
    // Initialize menu state
    initializeMenuState();
});

const isSuperUser = computed(
    () => !!page.props.auth?.user?.data?.is_super_user
);

// Get current domain from page props
const currentDomain = computed(() => page.props.domain);

// Helper function to detect domain from current URL
const getCurrentDomainFromUrl = () => {
    const currentPath = window.location.pathname;
    const domainMatch = currentPath.match(/\/domains\/([^\/]+)/);
    return domainMatch ? domainMatch[1] : null;
};

// Whether current URL is inside a domain context
const isInDomainContext = computed(() => !!getCurrentDomainFromUrl());

// Helper function to get dashboard tag text
const getDashboardTagText = () => {
    const currentDomainSlug = getCurrentDomainFromUrl();
    
    if (isSuperUser.value) {
        if (currentDomainSlug) {
            // Super user in domain context
            return currentDomainSlug.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        } else {
            // Super user in global context
            return 'Global Admin';
        }
    } else {
        // Regular user
        return currentDomain?.name || 'Organization';
    }
};

// Helper function to generate routes based on user type and current context
const getRoute = (routeName, params = {}) => {
    try {
        if (typeof window.route !== 'function') {
            console.warn('window.route is not a function');
            return "#";
        }
        
        // Check if we're currently in a domain context
        const currentDomainSlug = getCurrentDomainFromUrl();
        const isInDomainContext = currentDomainSlug !== null;
        
        console.log('Route generation debug:', {
            routeName,
            currentDomainSlug,
            isInDomainContext,
            isSuperUser: isSuperUser.value,
            currentDomain: currentDomain.value
        });
        
        // Super users can access both global and domain-specific routes
        if (isSuperUser.value) {
            // If we're in a domain context, maintain it
            if (isInDomainContext) {
                const domainRouteName = `domains.${routeName}`;
                const result = window.route(domainRouteName, { domain: currentDomainSlug, ...params });
                console.log('Generated domain route:', domainRouteName, '→', result);
                return result;
            }
            // Otherwise use global route
            const result = window.route(routeName, params);
            console.log('Generated global route:', routeName, '→', result);
            return result;
        } 
        // Regular users should use domain-specific routes
        else if (currentDomain.value || isInDomainContext) {
            const domainSlug = currentDomainSlug || currentDomain.value?.name_slug;
            const domainRouteName = `domains.${routeName}`;
            const result = window.route(domainRouteName, { domain: domainSlug, ...params });
            console.log('Generated domain route for regular user:', domainRouteName, '→', result);
            return result;
        }
        
        console.warn('No route generated for:', routeName);
        return "#";
    } catch (error) {
        console.warn('Route generation error:', error, 'for route:', routeName);
        return "#";
    }
};

// ===================================
// MENU ITEMS DEFINITION
// ===================================
const menuItems = [
    {
        key: "dashboard",
        title: "Dashboard",
        icon: IconDashboard,
        routeName: "dashboard",
        path: "/dashboard",
    },
    {
        key: "sales",
        title: "Sales",
        icon: IconHeartHandshake,
        routeName: "sales.index",
        path: "/sales",
    },
    {
        key: "domains",
        title: "Domains",
        icon: IconWorld,
        routeName: "domains.index",
        path: "/domains",
        superUserOnly: true,
        globalOnly: true,
    },
    {
        key: "products",
        title: "Products",
        icon: IconCategory,
        children: [
            {
                key: "products-items",
                title: "Items",
                routeName: "products.index",
                path: "/products",
            },
            {
                key: "products-categories",
                title: "Categories",
                routeName: "categories.index",
                path: "/categories",
            },
            {
                key: "products-discounts",
                title: "Discounts",
                routeName: "products.discounts.index",
                path: "/products/discounts",
            },
            {
                key: "mandatory-discounts",
                title: "Mandatory Discounts",
                routeName: "mandatory-discounts.index",
                path: "/mandatory-discounts",
            },
        ],
    },
    {
        key: "inventory",
        title: "Inventory",
        icon: IconPackage,
        children: [
            {
                key: "inventory-dashboard",
                title: "Dashboard",
                routeName: "inventory.index",
                path: "/inventory",
            },
            {
                key: "inventory-products",
                title: "Products",
                routeName: "inventory.products",
                path: "/inventory/products",
            },
            {
                key: "inventory-movements",
                title: "Movements",
                routeName: "inventory.movements",
                path: "/inventory/movements",
            },
            {
                key: "inventory-adjustments",
                title: "Stock Adjustments",
                routeName: "inventory.adjustments.index",
                path: "/inventory/adjustments",
            },
            {
                key: "inventory-locations",
                title: "Locations",
                routeName: "inventory.locations.index",
                path: "/inventory/locations",
            },
            {
                key: "inventory-valuation",
                title: "Valuation Report",
                routeName: "inventory.valuation",
                path: "/inventory/valuation",
            },
        ],
    },
    {
        key: "loyalty",
        title: "Loyalty Program",
        icon: IconGift,
        routeName: "loyalty.index",
        path: "/loyalty",
    },
    {
        key: "voids",
        title: "Void Logs",
        icon: IconHistory,
        routeName: "voids.index",
        path: "/void-logs",
    },
    {
        key: "customers",
        title: "Customers",
        icon: IconUsers,
        routeName: "customers.index",
        path: "/customers",
    },
    {
        key: "users",
        title: "Users",
        icon: IconUserCog,
        routeName: "users.index",
        path: "/users",
    },
    {
        key: "roles",
        title: "Roles",
        icon: IconUserCheck,
        routeName: "roles.index",
        path: "/roles",
    },
    {
        key: "permissions",
        title: "Permissions",
        icon: IconKey,
        routeName: "permissions.index",
        path: "/permissions",
        superUserOnly: true,
        globalOnly: true,
    },
];

// ===================================
// FILTER MENUS BASED ON PERMISSIONS
// ===================================
const menus = computed(() => {
    const filterMenuItems = (items) => {
        if (!Array.isArray(items)) {
            console.warn('Menu items is not an array:', items);
            return [];
        }
        
        return items
            .filter(item => {
                if (!item || typeof item !== 'object') {
                    return false;
                }
                
                // Hide global-only menus when browsing inside a domain
                if (isInDomainContext.value && item.globalOnly) {
                    return false;
                }

                // Check if item is super user only
                if (item.superUserOnly && !isSuperUser.value) {
                    return false;
                }
                
                // Check permission for items with routeName
                if (item.routeName && !hasPermission(item.routeName)) {
                    return false;
                }
                
                return true;
            })
            .map(item => {
                const filteredItem = { ...item };
                
                // Filter children if they exist
                if (item.children && Array.isArray(item.children)) {
                    const filteredChildren = filterMenuItems(item.children);
                    if (filteredChildren.length > 0) {
                        filteredItem.children = filteredChildren;
                    } else {
                        // Remove children if none are visible
                        delete filteredItem.children;
                    }
                }
                
                return filteredItem;
            });
    };
    
    const result = filterMenuItems(menuItems);
    console.log('Filtered menus result:', result);
    return result;
});

// ===================
// MENU CLICK HANDLER
// ===================
const handleClick = (menu) => {
    if (!menu.routeName) {
        console.warn('No routeName for menu:', menu);
        return;
    }
    
    try {
        const routePath = getRoute(menu.routeName);
        console.log('Navigation attempt:', {
            menu: menu.title,
            routeName: menu.routeName,
            currentPath: window.location.pathname,
            domainFromUrl: getCurrentDomainFromUrl(),
            generatedRoute: routePath,
            isValidRoute: routePath && routePath !== '#'
        });
        
        if (routePath && routePath !== '#') {
            selectedKeys.value = [menu.key];
            console.log('Navigating to:', routePath);
            router.visit(routePath);
        } else {
            console.error('Invalid route generated for menu:', menu.title, 'routeName:', menu.routeName, 'generated:', routePath);
        }
    } catch (error) {
        console.warn('Navigation error:', error, 'for menu:', menu);
    }
};

// ===================
// MENU STATE HANDLING
// ===================
const initializeMenuState = () => {
    const currentPath = window.location.pathname;
    
    // Find matching menu item
    const findMatchingMenu = (items, parentKey = null) => {
        for (const item of items) {
            if (item.children) {
                const childMatch = findMatchingMenu(item.children, item.key);
                if (childMatch) {
                    return childMatch;
                }
            } else {
                const routePath = getRoute(item.routeName);
                if (routePath && routePath === currentPath) {
                    return { item, parentKey };
                }
            }
        }
        return null;
    };
    
    const match = findMatchingMenu(safeMenus.value);
    if (match) {
        selectedKeys.value = [match.item.key];
        if (match.parentKey) {
            openKeys.value = [match.parentKey];
        }
    }
};

watch(() => page.url, () => initializeMenuState());

const handleOpenChange = (keys) => {
    openKeys.value = keys;
};

// Add a loading state to prevent rendering issues
const isMenuReady = computed(() => {
    const menuData = menus.value;
    return Array.isArray(menuData) && menuData.length >= 0;
});

// Ensure menus is always an array
const safeMenus = computed(() => {
    const menuData = menus.value;
    if (!Array.isArray(menuData)) {
        console.warn('Menus is not an array, returning empty array:', menuData);
        return [];
    }
    return menuData;
});
</script>

<template>
    <div class="overflow-auto">
        <a-menu
            v-if="isMenuReady"
            v-model:openKeys="openKeys"
            v-model:selectedKeys="selectedKeys"
            mode="inline"
            theme="light"
            :inlineCollapsed="false"
            @openChange="handleOpenChange"
        >
            <template v-for="menu in safeMenus" :key="menu.key">
                <!-- Menu Item without children -->
                <a-menu-item
                    v-if="!menu.children"
                    :key="`item-${menu.key}`"
                    @click="handleClick(menu)"
                    class="font-semibold text-gray-800"
                >
                    <template #icon>
                        <component
                            v-if="menu.icon"
                            :is="menu.icon"
                            class="flex-shrink-0"
                        />
                    </template>
                    <div class="flex items-center gap-2">
                        {{ menu.title }}
                        <span v-if="menu.title === 'Dashboard'" class="text-xs">
                            <a-tag :color="getCurrentDomainFromUrl() ? 'green' : 'blue'" class="text-[10px]">
                                {{ getDashboardTagText() }}
                            </a-tag>
                        </span>
                    </div>
                </a-menu-item>

                <!-- Sub Menu with children -->
                <a-sub-menu
                    v-else
                    :key="`submenu-${menu.key}`"
                    class="font-semibold text-gray-800"
                >
                    <template #icon>
                        <component
                            v-if="menu.icon"
                            :is="menu.icon"
                            class="flex-shrink-0"
                        />
                    </template>
                    <template #title>{{ menu.title }}</template>

                    <a-menu-item
                        v-for="child in menu.children"
                        :key="child.key"
                        @click="handleClick(child)"
                    >
                        <template #icon>
                            <component
                                v-if="child.icon"
                                :is="child.icon"
                                class="flex-shrink-0"
                            />
                        </template>
                        {{ child.title }}
                    </a-menu-item>
                </a-sub-menu>
            </template>
        </a-menu>
        
        <!-- Loading state -->
        <div v-else class="flex items-center justify-center p-4">
            <div class="text-gray-500">Loading menu...</div>
        </div>
    </div>
</template>
