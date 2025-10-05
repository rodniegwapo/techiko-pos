<script setup>
import { ref, computed, watch, onMounted } from "vue";
import {
    IconDashboard,
    IconCategory,
    IconUsers,
    IconBrandProducthunt,
    IconHeartHandshake,
    IconHistory,
    IconGift,
    IconUserCog,
    IconPackage,
    IconShield,
} from "@tabler/icons-vue";
import { router, usePage } from "@inertiajs/vue3";

import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissions } from "@/Composables/usePermissions";

const { selectedKeys, openKeys } = useGlobalVariables();
const page = usePage();

// Use permission composable
const {
    canViewDashboard,
    canViewCategories,
    canViewProducts,
    canViewSales,
    canViewInventory,
    canViewLoyalty,
    canViewCustomers,
    canManageUsers,
    canManageRoles,
    canViewVoidLogs
} = usePermissions();

const menus = computed(() => {
    const baseMenus = [];
    
    // Dashboard - All authenticated users
    if (canViewDashboard.value) {
        baseMenus.push({
            title: "Dashboard",
            path: route("dashboard"),
            icon: IconDashboard,
            pathName: "dashboard",
        });
    }
    
    // Sales - All authenticated users
    if (canViewSales.value) {
        baseMenus.push({
            title: "Sales",
            path: route("sales.index"),
            icon: IconHeartHandshake,
            pathName: "sales",
        });
    }
    
    // Products - Admin, Manager, Supervisor
    if (canViewProducts.value || canViewCategories.value) {
        const productChildren = [];
        
        if (canViewProducts.value) {
            productChildren.push({
                title: "Items",
                path: route("products.index"),
            });
        }
        
        if (canViewCategories.value) {
            productChildren.push({
                title: "Categories",
                path: route("categories.index"),
            });
        }
        
        if (canViewProducts.value) {
            productChildren.push({
                title: "Discounts",
                path: route("products.discounts.index"),
            });
            productChildren.push({
                title: "Mandatory Discounts",
                path: route("mandatory-discounts.index"),
            });
        }
        
        if (productChildren.length > 0) {
            baseMenus.push({
                title: "Products",
                icon: IconCategory,
                path: "/products",
                children: productChildren,
            });
        }
    }
    
    // Inventory - Admin, Manager
    if (canViewInventory.value) {
        const inventoryChildren = [
            {
                title: "Dashboard",
                path: route("inventory.index"),
            },
            {
                title: "Products",
                path: route("inventory.products"),
            },
            {
                title: "Movements",
                path: route("inventory.movements"),
            },
            {
                title: "Stock Adjustments",
                path: route("inventory.adjustments.index"),
            },
            {
                title: "Locations",
                path: route("inventory.locations.index"),
            },
            {
                title: "Valuation Report",
                path: route("inventory.valuation"),
            },
        ];
        
        baseMenus.push({
            title: "Inventory",
            icon: IconPackage,
            path: "/inventory",
            children: inventoryChildren,
        });
    }
    
    // Loyalty Program - Admin, Manager, Supervisor
    if (canViewLoyalty.value) {
        baseMenus.push({
            title: "Loyalty Program",
            path: route("loyalty.index"),
            icon: IconGift,
            pathName: "loyalty",
        });
    }
    
    // Void Logs - Admin, Manager, Supervisor
    if (canViewVoidLogs.value) {
        baseMenus.push({
            title: "Void logs",
            path: route("voids.index"),
            icon: IconHistory,
        });
    }
    
    // Customers - Admin, Manager, Supervisor
    if (canViewCustomers.value) {
        baseMenus.push({
            title: "Customers",
            path: "/customers",
            icon: IconUsers,
        });
    }

    // Add User Management menu item only for Super Admin and Admin
    if (canManageUsers.value) {
        baseMenus.push({
            title: "Users",
            path: route("users.index"),
            icon: IconUserCog,
            pathName: "users",
        });
    }

    // Add Role Management menu item only for Super Admin
    if (canManageRoles.value) {
        baseMenus.push({
            title: "Role Management",
            path: route("roles.index"),
            icon: IconShield,
            pathName: "roles",
        });
    }

    return baseMenus;
});

const handleClick = (menu, parentMenu = null) => {
    // Set the selected key first
    selectedKeys.value = [menu.path];

    // If this is a child menu, ensure parent stays open
    if (parentMenu) {
        const submenuKey = `submenu-${parentMenu.path}`;
        if (!openKeys.value.includes(submenuKey)) {
            openKeys.value = [...openKeys.value, submenuKey];
        }
    }

    // Navigate to the page
    router.visit(menu.path);
};

// Initialize menu state
const initializeMenuState = () => {
    const url = new URL(window.location.href);
    const currentPath = url.origin + url.pathname;

    // Find exact match first
    let matchedMenu = null;
    let parentMenu = null;

    // Check for exact match in child menus
    for (const menu of menus.value) {
        if (menu.children) {
            const childMatch = menu.children.find(
                (child) => child.path === currentPath
            );
            if (childMatch) {
                matchedMenu = childMatch;
                parentMenu = menu;
                break;
            }
        } else if (menu.path === currentPath) {
            matchedMenu = menu;
            break;
        }
    }

    // If no exact match, try to find parent route match (for create/edit pages)
    if (!matchedMenu) {
        for (const menu of menus.value) {
            if (menu.children) {
                const childMatch = menu.children.find(
                    (child) =>
                        currentPath.startsWith(child.path) &&
                        currentPath !== child.path
                );
                if (childMatch) {
                    matchedMenu = childMatch;
                    parentMenu = menu;
                    break;
                }
            } else if (
                currentPath.startsWith(menu.path) &&
                currentPath !== menu.path
            ) {
                matchedMenu = menu;
                break;
            }
        }
    }

    if (matchedMenu) {
        selectedKeys.value = [matchedMenu.path];

        if (parentMenu) {
            const submenuKey = `submenu-${parentMenu.path}`;
            // Preserve existing open keys and add the new one
            if (!openKeys.value.includes(submenuKey)) {
                openKeys.value = [...openKeys.value, submenuKey];
            }
        } else {
            // If it's a top-level menu, we might want to close submenus
            // but let's keep them open for better UX
        }
    }
};

onMounted(() => {
    initializeMenuState();
});

// Watch for route changes to maintain menu state
watch(
    () => page.url,
    () => {
        initializeMenuState();
    }
);

// Handle submenu open/close events
const handleOpenChange = (keys) => {
    openKeys.value = keys;
};
</script>

<template>
    <div class="overflow-auto">
        <a-menu
            v-model:openKeys="openKeys"
            v-model:selectedKeys="selectedKeys"
            mode="inline"
            theme="light"
            :inlineCollapsed="false"
            @openChange="handleOpenChange"
        >
            <template v-for="menu in menus" :key="menu.path">
                <a-menu-item
                    v-if="!menu.children"
                    :key="menu.path"
                    @click="handleClick(menu)"
                    class="font-semibold text-gray-800 items-center"
                >
                    <template #icon>
                        <span
                            class="leading-[40px] h-full flex items-center justify-center"
                        >
                            <component
                                v-if="menu.icon"
                                :is="menu.icon"
                                class="flex-shrink-0"
                            />
                        </span>
                    </template>
                    <div class="flex items-center gap-2">
                        {{ menu.title }}
                        <span v-if="menu.title == 'Dashboard'" class="text-xs">
                            <a-tag color="blue" class="text-[10px]">
                                {{ page.props.default_store.name }}</a-tag
                            ></span
                        >
                    </div>
                </a-menu-item>

                <a-sub-menu
                    v-else
                    :key="`submenu-${menu.path}`"
                    class="font-semibold text-gray-800 items-center"
                >
                    <template #icon>
                        <span
                            class="leading-[40px] h-full flex items-center justify-center"
                        >
                            <component
                                v-if="menu.icon"
                                :is="menu.icon"
                                class="flex-shrink-0"
                            />
                        </span>
                    </template>
                    <template #title>{{ menu.title }}</template>

                    <a-menu-item
                        v-for="child in menu.children"
                        :key="child.path"
                        @click="handleClick(child, menu)"
                    >
                        <component
                            v-if="child.icon"
                            :is="child.icon"
                            style="font-size: 20px"
                        />
                        <div class="flex items-center gap-2">
                            <span>{{ child.title }}</span>
                            <span
                                v-if="child.title == 'Dashboard'"
                                class="text-xs"
                            >
                                <a-tag color="blue" class="text-[10px]">
                                    {{ page.props.default_store.name }}</a-tag
                                ></span
                            >
                        </div>
                    </a-menu-item>
                </a-sub-menu>
            </template>
        </a-menu>
    </div>
</template>
