<script setup>
import { Link } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { financeQuery } from "../composables/useFinanceFilters";

/** Tabs between the Finance pages, keeping the chosen period and location. */
const props = defineProps({
    active: { type: String, required: true },
    filters: { type: Object, required: true },
});

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();

// `activeOn`: other route names that count as this tab (the income statement is the Profit & Loss report).
const tabs = [
    { routeName: "finance.dashboard", label: "Overview" },
    { routeName: "finance.income-statement", label: "Income statement", activeOn: ["profit-loss.index"] },
    { routeName: "finance.cash-flow", label: "Cash flow" },
    { routeName: "finance.balance-sheet", label: "Balance sheet" },
    { routeName: "finance.receivables", label: "Customer credit" },
    { routeName: "finance.payables.index", label: "Supplier bills" },
    { routeName: "expenses.index", label: "Expenses", dates: true },
    { routeName: "finance.other-income.index", label: "Other income" },
    { routeName: "finance.balance-items.index", label: "Accounts, loans & assets", plain: true },
    { routeName: "finance.reviews.index", label: "Monthly reviews", plain: true },
].filter((tab) => hasPermission(tab.routeName));

function href(tab) {
    // Pages that are not about a period take no filters at all.
    if (tab.plain) return getRoute(tab.routeName);
    // The Expenses page filters by plain dates rather than a Finance period.
    const query = tab.dates
        ? { start_date: props.filters.start_date, end_date: props.filters.end_date }
        : financeQuery(props.filters);
    return `${getRoute(tab.routeName)}?${new URLSearchParams(query).toString()}`;
}

const isActive = (tab) => props.active === tab.routeName || (tab.activeOn ?? []).includes(props.active);
</script>

<template>
    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200" data-testid="finance-nav">
        <Link
            v-for="tab in tabs"
            :key="tab.routeName"
            :href="href(tab)"
            class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
            :class="isActive(tab) ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-800'"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
