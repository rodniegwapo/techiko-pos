<script setup>
import { computed, watch, onMounted, inject, ref } from "vue";
import { IconLock, IconSearch } from "@tabler/icons-vue";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useNavMenu } from "@/Composables/useNavMenu";
import { openNavSearch } from "@/Composables/useNavSearch";

const props = defineProps({
    // The desktop sidebar's icon-only rail: icons with tooltips, submenus open as flyouts.
    collapsed: { type: Boolean, default: false },
});

const page = usePage();
const { selectedKeys, openKeys } = useGlobalVariables();
const {
    menus,
    topItems,
    sections,
    navigate,
    menuLocked,
    isSuperUser,
    getCurrentDomainFromUrl,
    getRoute,
} = useNavMenu();

// Ensure selectedKeys and openKeys are arrays
if (!Array.isArray(selectedKeys.value)) {
    selectedKeys.value = [];
}
if (!Array.isArray(openKeys.value)) {
    openKeys.value = [];
}

onMounted(() => initializeMenuState());

// Every page draws the menu anew, so picking an item low in the list would scroll it back to the
// top. Remember how far down it was and put it back.
const SCROLL_KEY = "techiko.sidebar.scrollTop";
const scroller = ref(null);
onMounted(() => {
    try {
        const top = Number(sessionStorage.getItem(SCROLL_KEY));
        if (scroller.value && top > 0) scroller.value.scrollTop = top;
    } catch {
        // Storage blocked: the menu starts at the top.
    }
});
function rememberScroll() {
    try {
        sessionStorage.setItem(SCROLL_KEY, String(scroller.value?.scrollTop ?? 0));
    } catch {
        // Nothing to remember it in.
    }
}

const inquiryUnreadCountInjected = inject("inquiryUnreadCount", null);
const inquiryUnreadCount = computed(() => {
    if (
        inquiryUnreadCountInjected != null &&
        typeof inquiryUnreadCountInjected === "object" &&
        "value" in inquiryUnreadCountInjected
    ) {
        return Number(inquiryUnreadCountInjected.value) || 0;
    }
    return Number(page.props.inquiryUnreadCount) || 0;
});

// Helper function to get dashboard tag text
const getDashboardTagText = () => {
    const currentDomainSlug = getCurrentDomainFromUrl();

    if (isSuperUser.value) {
        if (currentDomainSlug) {
            // Super user in domain context
            return currentDomainSlug
                .replace(/-/g, " ")
                .replace(/\b\w/g, (l) => l.toUpperCase());
        }
        // Super user in global context
        return "Global Admin";
    }
    // Regular user
    return page.props.currentDomain?.name || "Organization";
};

const isMac = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform || "");
const searchShortcut = isMac ? "⌘K" : "Ctrl K";

const handleClick = (menu) => {
    const key = navigate(menu);
    if (key) {
        selectedKeys.value = [key];
    }
};

// ===================
// MENU STATE HANDLING
// ===================
const initializeMenuState = () => {
    const currentPath = window.location.pathname;

    // Special handling for dashboard routes
    if (currentPath.includes("/dashboard")) {
        selectedKeys.value = ["dashboard"];
        openKeys.value = [];
        return;
    }

    // Find matching menu item
    const findMatchingMenu = (items, parentKey = null) => {
        for (const item of items) {
            if (item.children) {
                const childMatch = findMatchingMenu(item.children, item.key);
                if (childMatch) {
                    return childMatch;
                }
            } else if (item.routeName) {
                const routePath = getRoute(item.routeName);
                // A group shown as one entry stays selected on any of its pages.
                if ((routePath && routePath === currentPath) || (item.activePathPrefix && currentPath.includes(item.activePathPrefix))) {
                    return { item, parentKey };
                }
            }
        }
        return null;
    };

    const match = findMatchingMenu(menus.value);
    if (match) {
        selectedKeys.value = [match.item.key];
        if (match.parentKey && !props.collapsed) {
            openKeys.value = [`submenu-${match.parentKey}`];
        }
    }
};

watch(
    () => page.url,
    () => initializeMenuState(),
);

const handleOpenChange = (keys) => {
    openKeys.value = keys;
};
</script>

<template>
    <div ref="scroller" class="overflow-auto" data-testid="sidebar-menu" @scroll.passive="rememberScroll">
        <p
            v-if="menuLocked"
            class="mx-3 mb-2 flex items-center gap-1.5 rounded-md bg-red-50 px-2 py-1.5 text-xs text-red-700"
            data-testid="menu-locked"
        >
            <IconLock :size="14" class="shrink-0" />
            <span v-if="!collapsed">Subscription on hold</span>
        </p>

        <!-- Quick page search (Ctrl+K anywhere) -->
        <div v-else class="mb-1" :class="collapsed ? 'flex justify-center' : 'px-3'">
            <a-tooltip v-if="collapsed" :title="`Search pages (${searchShortcut})`" placement="right">
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                    aria-label="Search pages"
                    data-testid="nav-search-open"
                    @click="openNavSearch"
                >
                    <IconSearch :size="18" />
                </button>
            </a-tooltip>
            <button
                v-else
                type="button"
                class="flex w-full items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-sm text-gray-500 hover:border-gray-300 hover:text-gray-700"
                aria-label="Search pages"
                data-testid="nav-search-open"
                @click="openNavSearch"
            >
                <IconSearch :size="16" class="shrink-0" />
                <span class="flex-1 text-left">Search pages…</span>
                <kbd class="rounded border border-gray-200 bg-white px-1.5 text-[10px] font-medium text-gray-400">
                    {{ searchShortcut }}
                </kbd>
            </button>
        </div>

        <a-menu
            v-model:openKeys="openKeys"
            v-model:selectedKeys="selectedKeys"
            mode="inline"
            theme="light"
            :inlineCollapsed="collapsed"
            class="nav-menu"
            @openChange="handleOpenChange"
        >
            <!-- Dashboard, above the sections -->
            <a-menu-item
                v-for="menu in topItems"
                :key="menu.key"
                :disabled="menuLocked"
                @click="handleClick(menu)"
                class="font-semibold text-gray-800"
            >
                <template #icon>
                    <component :is="menu.icon" class="flex-shrink-0" />
                </template>
                <div class="flex items-center gap-2">
                    {{ menu.title }}
                    <span v-if="menu.key === 'dashboard' && !collapsed" class="text-xs">
                        <a-tag
                            :color="getCurrentDomainFromUrl() ? 'green' : 'blue'"
                            class="text-[10px]"
                        >
                            {{ getDashboardTagText() }}
                        </a-tag>
                    </span>
                </div>
            </a-menu-item>

            <a-menu-item-group
                v-for="section in sections"
                :key="`section-${section.key}`"
            >
                <template #title>
                    <span
                        v-if="collapsed"
                        class="nav-section-divider block"
                        :data-testid="`nav-section-${section.key}`"
                    />
                    <span
                        v-else
                        class="text-[11px] font-semibold uppercase tracking-wide text-gray-400"
                        :data-testid="`nav-section-${section.key}`"
                    >
                        {{ section.title }}
                    </span>
                </template>

                <template v-for="menu in section.items" :key="menu.key">
                    <!-- Menu Item without children -->
                    <a-menu-item
                        v-if="!menu.children"
                        :key="menu.key"
                        :disabled="menuLocked"
                        @click="handleClick(menu)"
                        class="font-semibold text-gray-800"
                    >
                        <template #icon>
                            <span class="nav-icon relative inline-flex">
                                <component :is="menu.icon" class="flex-shrink-0" />
                                <span
                                    v-if="collapsed && (menu.upgrade || (menu.key === 'messages' && inquiryUnreadCount > 0))"
                                    class="absolute -right-1 -top-1 h-2 w-2 rounded-full"
                                    :class="menu.upgrade ? 'bg-amber-500' : 'bg-red-500'"
                                />
                            </span>
                        </template>
                        <div class="flex items-center gap-2">
                            {{ menu.title }}
                            <span
                                v-if="menu.upgrade"
                                class="flex items-center gap-0.5 text-[10px] font-normal text-amber-600"
                                data-testid="menu-upgrade"
                            >
                                <IconLock :size="12" /> Upgrade
                            </span>
                            <span
                                v-else-if="menu.key === 'messages' && inquiryUnreadCount > 0"
                                class="ml-1 min-w-[1.1rem] rounded-full bg-red-500 px-1.5 text-center text-[10px] leading-tight text-white"
                            >
                                {{ inquiryUnreadCount }}
                            </span>
                        </div>
                    </a-menu-item>

                    <!-- Sub Menu with children (a flyout in the rail) -->
                    <a-sub-menu
                        v-else
                        :key="`submenu-${menu.key}`"
                        :disabled="menuLocked"
                        class="font-semibold text-gray-800"
                    >
                        <template #icon>
                            <span class="nav-icon relative inline-flex">
                                <component :is="menu.icon" class="flex-shrink-0" />
                                <span
                                    v-if="collapsed && menu.upgrade"
                                    class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-amber-500"
                                />
                            </span>
                        </template>
                        <template #title>
                            <span class="inline-flex items-center gap-2">
                                {{ menu.title }}
                                <IconLock v-if="menu.upgrade" :size="12" class="text-amber-600" />
                            </span>
                        </template>

                        <a-menu-item
                            v-for="child in menu.children"
                            :key="child.key"
                            @click="handleClick(child)"
                        >
                            <span class="inline-flex items-center gap-2">
                                {{ child.title }}
                                <span
                                    v-if="child.upgrade"
                                    class="flex items-center gap-0.5 text-[10px] text-amber-600"
                                    data-testid="menu-upgrade"
                                >
                                    <IconLock :size="12" /> Upgrade
                                </span>
                            </span>
                        </a-menu-item>
                    </a-sub-menu>
                </template>
            </a-menu-item-group>
        </a-menu>
    </div>
</template>

<style scoped>
/* Section headings sit tight above their items. */
.nav-menu :deep(.ant-menu-item-group-title) {
    padding: 12px 16px 4px 24px;
}
.nav-menu.ant-menu-inline-collapsed :deep(.ant-menu-item-group-title) {
    padding: 6px 16px;
}
.nav-section-divider {
    height: 1px;
    background: #e5e7eb;
}
</style>
