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

const { selectedKeys, openKeys } = useGlobalVariables();
const page = usePage();

// ======================
// USER PERMISSIONS LOGIC
// ======================
const userPermissionNames = computed(() =>
    (page.props.auth?.user?.data?.permissions || []).map((p) => p.name)
);

// Check if user has a specific permission
const hasPerm = (perm) => userPermissionNames.value.includes(perm);

// Optional: Support wildcard like "sales.*"
const hasPermLike = (perm) => {
    const base = perm.split(".")[0];
    return userPermissionNames.value.some(
        (p) => p === perm || p.startsWith(`${base}.`)
    );
};

// ======================
// STATIC MENU DEFINITION
// ======================
const allMenus = [
    {
        title: "Dashboard",
        icon: IconDashboard,
        routeName: "dashboard",
    },
    {
        title: "Sales",
        icon: IconHeartHandshake,
        routeName: "sales.index",
    },
    {
        title: "Products",
        icon: IconCategory,
        children: [
            { title: "Items", routeName: "products.index" },
            { title: "Categories", routeName: "categories.index" },
            { title: "Discounts", routeName: "products.discounts.index" },
            {
                title: "Mandatory Discounts",
                routeName: "mandatory-discounts.index",
            },
        ],
    },
    {
        title: "Inventory",
        icon: IconPackage,
        children: [
            { title: "Dashboard", routeName: "inventory.index" },
            { title: "Products", routeName: "inventory.products" },
            { title: "Movements", routeName: "inventory.movements" },
            {
                title: "Stock Adjustments",
                routeName: "inventory.adjustments.index",
            },
            { title: "Locations", routeName: "inventory.locations.index" },
            { title: "Valuation Report", routeName: "inventory.valuation" },
        ],
    },
    {
        title: "Loyalty Program",
        icon: IconGift,
        routeName: "loyalty.index",
    },
    {
        title: "Void Logs",
        icon: IconHistory,
        routeName: "voids.index",
    },
    {
        title: "Customers",
        icon: IconUsers,
        routeName: "customers.index",
    },
    {
        title: "Users",
        icon: IconUserCog,
        routeName: "users.index",
    },
    {
        title: "Role Management",
        icon: IconShield,
        routeName: "roles.index",
    },
];

// ===================================
// FILTER MENUS BASED ON PERMISSIONS
// ===================================
const menus = computed(() => {
    const filterMenu = (menuList) => {
        return menuList
            .map((menu) => {
                if (menu.children) {
                    const filteredChildren = filterMenu(menu.children);
                    if (filteredChildren.length > 0) {
                        return { ...menu, children: filteredChildren };
                    }
                } else if (
                    menu.routeName &&
                    (hasPerm(menu.routeName) || hasPermLike(menu.routeName))
                ) {
                    return { ...menu, path: route(menu.routeName) };
                }
                return null;
            })
            .filter(Boolean);
    };

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

onMounted(() => {
    initializeMenuState();
});

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
                <!-- Single menu -->
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
                                {{ page.props.default_store.name }}
                            </a-tag>
                        </span>
                    </div>
                </a-menu-item>

                <!-- Sub-menu -->
                <a-sub-menu
                    v-else
                    :key="`submenu-${menu.path || menu.title}`"
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
                                    {{ page.props.default_store.name }}
                                </a-tag>
                            </span>
                        </div>
                    </a-menu-item>
                </a-sub-menu>
            </template>
        </a-menu>
    </div>
</template>
