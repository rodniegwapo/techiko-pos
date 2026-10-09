<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useFinanceFilters } from "./composables/useFinanceFilters";
import FinanceNav from "./components/FinanceNav.vue";
import FinancePeriod from "./components/FinancePeriod.vue";
import ExplainButton from "./components/ExplainButton.vue";
import MetricCard from "./components/MetricCard.vue";

const props = defineProps({
    filters: { type: Object, required: true },
    locations: { type: Array, default: () => [] },
    aiEnabled: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
    receivables: { type: Object, required: true },
});

const { formattedTotal } = useHelpers();
const { getRoute } = useDomainRoutes();
// Customer credit covers the whole business, so there is no location filter here.
const { filters, filtersConfig, activeFilters, handleClearSelectedFilter, clearAll, load, spinning, periodLabel, previousLabel } =
    useFinanceFilters({ routeName: "finance.receivables", serverFilters: () => props.filters, showLocation: false });

const ar = computed(() => props.receivables);

const collectedChange = computed(() => {
    const now = ar.value.period.collected;
    const before = ar.value.previous_period.collected;
    return { amount: now - before, pct: before ? Math.round(((now - before) / before) * 1000) / 10 : null };
});

const agingRows = computed(() => {
    const labels = { "1_30": "1–30 days late", "31_60": "31–60 days late", "61_90": "61–90 days late", over_90: "Over 90 days late" };
    const total = ar.value.overdue || 0;
    return Object.entries(ar.value.aging).map(([key, amount]) => ({
        key,
        label: labels[key] ?? key,
        amount,
        pct: total > 0 ? Math.round((amount / total) * 100) : 0,
    }));
});

const largestColumns = [
    { title: "Customer", dataIndex: "name", key: "name", ellipsis: true },
    { title: "Owes", dataIndex: "balance", key: "balance", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "Overdue", dataIndex: "overdue", key: "overdue", align: "right", customRender: ({ text }) => (text > 0 ? formattedTotal(text) : "—") },
    { title: "Credit limit", dataIndex: "credit_limit", key: "credit_limit", align: "right", customRender: ({ text }) => formattedTotal(text) },
];

const overdueColumns = [
    { title: "Customer", dataIndex: "name", key: "name", ellipsis: true },
    { title: "Overdue", dataIndex: "overdue", key: "overdue", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "Late by", dataIndex: "days_overdue", key: "days_overdue", align: "right", width: 100, customRender: ({ text }) => `${text} days` },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Customer credit" />
        <ContentHeader class="mb-4 md:mb-6" title="Customer credit" />
        <ContentLayout title="How much do my customers owe me?" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="load()" />
                <ExplainButton topic="receivables" :filters="props.filters" :ai-enabled="aiEnabled"
                    label="Explain my customer credit" title="Your customer credit in plain words" type="primary" size="middle" />
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <template #activeFilters>
                <ActiveFilters :filters="activeFilters" @remove-filter="handleClearSelectedFilter" @clear-all="clearAll" />
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.receivables" :filters="props.filters" />
                    <FinancePeriod :label="periodLabel" :previous="previousLabel" note="Balances are as of today" />

                    <p class="text-gray-800">
                        Customers owe you <strong>{{ formattedTotal(ar.outstanding) }}</strong>.
                        <template v-if="ar.overdue > 0">
                            <strong class="text-red-600">{{ formattedTotal(ar.overdue) }}</strong> of it is overdue.
                        </template>
                        <template v-else>None of it is overdue.</template>
                    </p>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <MetricCard label="Total owed to you" :value="ar.outstanding" :hint="`${ar.customers_owing} customer(s) with a balance`" />
                        <MetricCard label="Overdue" :value="ar.overdue" :hint="`${ar.overdue_pct}% of what's owed`" />
                        <MetricCard label="Due in the next 7 days" :value="ar.due_within_7_days" hint="Not late yet" />
                        <MetricCard label="Collected this period" :value="ar.period.collected" :change="collectedChange"
                            :hint="`${formattedTotal(ar.period.new_credit)} newly given on credit`" />
                    </div>

                    <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                        <h2 class="text-base font-semibold text-gray-900">How late are overdue payments?</h2>
                        <div v-if="ar.overdue <= 0" class="text-sm text-gray-500">No overdue payments.</div>
                        <div v-for="row in agingRows" v-else :key="row.key" class="space-y-1">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">{{ row.label }}</span>
                                <span class="font-medium text-gray-900">{{ formattedTotal(row.amount) }}</span>
                            </div>
                            <a-progress :percent="row.pct" :show-info="false" size="small"
                                :stroke-color="row.key === '1_30' ? '#f59e0b' : '#dc2626'" />
                        </div>
                    </section>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <section class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900">Overdue customers</h2>
                            <a-table :columns="overdueColumns" :data-source="ar.overdue_customers" :pagination="false"
                                row-key="customer_id" size="small" bordered class="bg-white" />
                        </section>
                        <section class="space-y-3">
                            <div class="flex items-baseline justify-between gap-2">
                                <h2 class="text-base font-semibold text-gray-900">Largest balances</h2>
                                <span v-if="ar.outstanding > 0" class="text-xs text-gray-500">Top 3 hold {{ ar.top_three_share_pct }}% of the total</span>
                            </div>
                            <a-table :columns="largestColumns" :data-source="ar.largest_balances" :pagination="false"
                                row-key="customer_id" size="small" bordered class="bg-white" :scroll="{ x: 440 }" />
                        </section>
                    </div>

                    <p class="text-sm text-gray-600">
                        To record a payment or follow up a customer, go to
                        <Link :href="getRoute('credits.index')" class="text-blue-600 hover:underline">Credit Management</Link>.
                        Customer credit covers the whole business, so there is no location filter here.
                    </p>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
