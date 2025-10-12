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
} from "@tabler/icons-vue";
import { router, usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const { selectedKeys, openKeys } = useGlobalVariables();
const page = usePage();
const { hasPermission } = usePermissionsV2();

const isSuperUser = computed(
    () => !!page.props.auth?.user?.data?.is_super_user
);

// ===================================
// STATIC MENU DEFINITION
// ===================================
const allMenus = [
    {
        title: "Dashboard",
        icon: IconDashboard,
        routeName: "dashboard",
        path: route("dashboard"),
    },
    {
        title: "Sales",
        icon: IconHeartHandshake,
        routeName: "sales.index",
        path: route("sales.index"),
    },
    {
        title: "Products",
        icon: IconCategory,
        children: [
            {
                title: "Items",
                routeName: "products.index",
                path: route("products.index"),
            },
            {
                title: "Categories",
                routeName: "categories.index",
                path: route("categories.index"),
            },
            {
                title: "Discounts",
                routeName: "products.discounts.index",
                path: route("products.discounts.index"),
            },
            {
                title: "Mandatory Discounts",
                routeName: "mandatory-discounts.index",
                path: route("mandatory-discounts.index"),
            },
        ],
    },
    {
        title: "Inventory",
        icon: IconPackage,
        routeName: "inventory.index",
        children: [
            {
                title: "Dashboard",
                routeName: "inventory.index",
                path: route("inventory.index"),
            },
            {
                title: "Products",
                routeName: "inventory.products",
                path: route("inventory.products"),
            },
            {
                title: "Movements",
                routeName: "inventory.movements",
                path: route("inventory.movements"),
            },
            {
                title: "Stock Adjustments",
                routeName: "inventory.adjustments.index",
                path: route("inventory.adjustments.index"),
            },
            {
                title: "Locations",
                routeName: "inventory.locations.index",
                path: route("inventory.locations.index"),
            },
            {
                title: "Valuation Report",
                routeName: "inventory.valuation",
                path: route("inventory.valuation"),
            },
        ],
    },
    {
        title: "Loyalty Program",
        icon: IconGift,
        routeName: "loyalty.index",
        path: route("loyalty.index"),
    },
    {
        title: "Void Logs",
        icon: IconHistory,
        routeName: "voids.index",
        path: route("voids.index"),
    },
    {
        title: "Customers",
        icon: IconUsers,
        routeName: "customers.index",
        path: route("customers.index"),
    },
    {
        title: "Users",
        icon: IconUserCog,
        routeName: "users.index",
        path: route("users.index"),
    },
    {
        title: "Role Management",
        icon: IconShield,
        routeName: "roles.index",
        path: route("roles.index"),
    },
    {
        title: "Permission Management",
        icon: IconShield,
        routeName: "permissions.index",
        path: route("permissions.index"),
    },
];

// ===================================
// FILTER MENUS BASED ON PERMISSIONS
// ===================================
const menus = computed(() => {
    if (isSuperUser.value) return allMenus;

    const filterMenu = (list) =>
        list
            .map((item) => {
                const hasChildren =
                    Array.isArray(item.children) && item.children.length > 0;

                if (hasChildren) {
                    const visibleChildren = filterMenu(item.children);
                    const parentAllowed = item.routeName
                        ? hasPermission(item.routeName)
                        : false;
                    if (parentAllowed || visibleChildren.length > 0) {
                        return { ...item, children: visibleChildren };
                    }
                    return null;
                }

                // Hide if routeName missing or no permission
                if (!item.routeName) return null;
                if (!hasPermission(item.routeName)) return null;

                return item;
            })
            .filter(Boolean);

    return filterMenu(allMenus);
});

// ===================
// MENU CLICK HANDLER
// ===================
const handleClick = (menu, parentMenu = null) => {
    selectedKeys.value = [menu.path];
    if (parentMenu) {
        const submenuKey = `submenu-${parentMenu.path}`;
        if (!openKeys.value.includes(submenuKey)) {
            openKeys.value = [...openKeys.value, submenuKey];
        }
    }
    router.visit(menu.path);
};

// ===================
// MENU STATE HANDLING
// ===================
const initializeMenuState = () => {
    const url = new URL(window.location.href);
    const currentPath = url.origin + url.pathname;

    let matchedMenu = null;
    let parentMenu = null;

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

    if (matchedMenu) {
        selectedKeys.value = [matchedMenu.path];
        if (parentMenu) {
            const submenuKey = `submenu-${parentMenu.path}`;
            if (!openKeys.value.includes(submenuKey)) {
                openKeys.value = [...openKeys.value, submenuKey];
            }
        }
    }
};

onMounted(() => initializeMenuState());
watch(
    () => page.url,
    () => initializeMenuState()
);

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
                        <component
                            v-if="menu.icon"
                            :is="menu.icon"
                            class="flex-shrink-0"
                        />
                    </template>
                    <div class="flex items-center gap-2">
                        {{ menu.title }}
                        <span v-if="menu.title === 'Dashboard'" class="text-xs">
                            <a-tag color="blue" class="text-[10px]">
                                {{ page.props.default_store.name }}
                            </a-tag>
                        </span>
                    </div>
                </a-menu-item>

                <a-sub-menu
                    v-else
                    :key="`submenu-${menu.path || menu.title}`"
                    class="font-semibold text-gray-800 items-center"
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
                        </div>
                    </a-menu-item>
                </a-sub-menu>
            </template>
        </a-menu>
    </div>
</template>
