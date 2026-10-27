<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useWalletQuery } from "../composables/useWalletQuery";

/** The wallet pages' frame: title and the four pages as tabs, keeping store and date. */
const props = defineProps({
    title: { type: String, required: true },
    /** drawer | cash-in-out | balances | history | counts */
    active: { type: String, required: true },
    activeLocation: { type: Object, default: null },
});

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const { keepQuery } = useWalletQuery(() => props.activeLocation);

const PAGES = [
    { key: "drawer", route: "wallet.drawer", label: "Today's drawer" },
    { key: "cash-in-out", route: "wallet.cash-in-out", label: "Cash in & out" },
    { key: "balances", route: "wallet.balances", label: "Where money is" },
    { key: "history", route: "wallet.history", label: "Drawer history" },
    { key: "counts", route: "register-shifts.index", label: "Counted money" },
];

const tabs = computed(() =>
    PAGES.filter((p) => hasPermission(p.route)).map((p) => {
        const url = getRoute(p.route);
        const query = new URLSearchParams(keepQuery.value).toString();
        return { ...p, href: query ? `${url}?${query}` : url };
    }),
);
</script>

<template>
    <Head :title="title" />
    <AuthenticatedLayout>
        <ContentHeader class="mb-3" :title="title" />
        <nav v-if="tabs.length > 1" class="mb-5 flex max-w-7xl gap-1 overflow-x-auto border-b border-gray-200" data-testid="wallet-tabs">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
                :class="tab.key === active ? 'border-teal-600 text-teal-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
                :data-testid="`wallet-tab-${tab.key}`"
            >
                {{ tab.label }}
            </Link>
        </nav>
        <div class="max-w-7xl">
            <slot />
        </div>
    </AuthenticatedLayout>
</template>
