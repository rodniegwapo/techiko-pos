<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import dayjs from "dayjs";
import VueApexCharts from "vue3-apexcharts";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useFinanceFilters } from "./composables/useFinanceFilters";
import FinanceNav from "./components/FinanceNav.vue";
import FinancePeriod from "./components/FinancePeriod.vue";
import ExplainButton from "./components/ExplainButton.vue";
import MetricCard from "./components/MetricCard.vue";
import HealthSummary from "./components/HealthSummary.vue";
import AskPanel from "./components/AskPanel.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const props = defineProps({
    filters: { type: Object, required: true },
    locations: { type: Array, default: () => [] },
    aiEnabled: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
    overview: { type: Object, required: true },
    health: { type: Array, default: () => [] },
    recommendations: { type: Array, default: () => [] },
    trend: { type: Array, default: () => [] },
    latestReview: { type: Object, default: null },
    suggestedQuestions: { type: Array, default: () => [] },
});

const { formattedTotal } = useHelpers();
const { getRoute } = useDomainRoutes();
const { filters, filtersConfig, activeFilters, handleClearSelectedFilter, clearAll, load, spinning, periodLabel, previousLabel } =
    useFinanceFilters({ routeName: "finance.dashboard", serverFilters: () => props.filters, locations: props.locations });

const current = computed(() => props.overview.current);
const changes = computed(() => props.overview.changes);
const ar = computed(() => props.overview.receivables);
const ap = computed(() => props.overview.payables);
const moneyIn = computed(() => props.overview.money_in);

/** Cash on hand: what the drawers should hold today plus the bank and e-wallet balances entered. */
const cashBalance = computed(() => {
    const drawer = props.overview.cash_in_drawer.amount;
    const accounts = props.overview.account_balances?.closing_total ?? 0;
    return { drawer, accounts, total: Math.round((drawer + accounts) * 100) / 100 };
});

/**
 * Balances against the end of the previous period, from the daily snapshots (whole business).
 * Empty until enough history exists, so those cards show no comparison yet.
 */
const positionChange = computed(() => props.overview.position?.changes ?? {});

/** Largest running cost (other expenses such as loan interest sit below operating profit). */
const biggestOperating = computed(() => props.overview.expense_breakdown.find((row) => row.type === "operating") ?? null);

const reviewLabel = computed(() =>
    props.latestReview ? dayjs(`${props.latestReview.month}-01`).format("MMMM YYYY") : "",
);

const slowColumns = [
    { title: "Product", dataIndex: "name", key: "name", ellipsis: true },
    { title: "Tied up", dataIndex: "value", key: "value", align: "right", customRender: ({ text }) => formattedTotal(text) },
    {
        title: "Lasts", dataIndex: "days_of_stock", key: "days", align: "right", width: 120,
        customRender: ({ text }) => (text === null ? "Not sold (30d)" : `~${text} days`),
    },
];

const moneyInRows = computed(() =>
    [
        { label: "Cash", value: moneyIn.value.cash },
        { label: "Card", value: moneyIn.value.card },
        { label: "E-wallet", value: moneyIn.value["e-wallet"] },
        { label: "Bank transfer", value: moneyIn.value.bank },
        { label: "Credit payments collected", value: moneyIn.value.credit_collections },
    ].filter((r) => r.value > 0),
);

const chartSeries = computed(() => [
    { name: "Revenue", type: "column", data: props.trend.map((t) => t.revenue) },
    { name: "Gross profit", type: "column", data: props.trend.map((t) => t.gross_profit) },
    { name: "Net profit", type: "line", data: props.trend.map((t) => t.net_profit) },
]);

const chartOptions = computed(() => ({
    chart: { type: "line", toolbar: { show: false }, fontFamily: "inherit" },
    plotOptions: { bar: { columnWidth: "55%", borderRadius: 3 } },
    stroke: { width: [0, 0, 3] },
    dataLabels: { enabled: false },
    colors: ["#2563eb", "#10b981", "#f59e0b"],
    xaxis: { categories: props.trend.map((t) => t.label) },
    yaxis: { labels: { formatter: (v) => `₱${Math.round(v).toLocaleString("en-PH")}` } },
    tooltip: { shared: true, y: { formatter: (v) => formattedTotal(v) } },
    legend: { position: "top", horizontalAlign: "left" },
    grid: { borderColor: "#f1f5f9" },
}));

const productColumns = [
    { title: "Product", dataIndex: "name", key: "name", ellipsis: true },
    { title: "Sales", dataIndex: "sales", key: "sales", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "Profit", dataIndex: "profit", key: "profit", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "Margin", dataIndex: "margin_pct", key: "margin_pct", align: "right", width: 90, customRender: ({ text }) => `${text}%` },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Finance overview" />
        <ContentHeader class="mb-4 md:mb-6" title="Finance" />
        <ContentLayout title="How is the business doing?" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="load()" />
                <ExplainButton
                    topic="overview"
                    :filters="props.filters"
                    :ai-enabled="aiEnabled"
                    label="Explain my finances"
                    title="Your finances in plain words"
                    type="primary"
                    size="middle"
                />
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <template #activeFilters>
                <ActiveFilters :filters="activeFilters" @remove-filter="handleClearSelectedFilter" @clear-all="clearAll" />
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.dashboard" :filters="props.filters" />
                    <FinancePeriod :label="periodLabel" :previous="previousLabel" />

                    <a-alert v-if="latestReview && !latestReview.read_at" type="success" show-icon data-testid="review-ready">
                        <template #message>
                            Your {{ reviewLabel }} business review is ready.
                            <Link :href="`${getRoute('finance.reviews.index')}?month=${latestReview.month}`" class="font-medium text-blue-600 hover:underline">
                                Read it
                            </Link>
                        </template>
                    </a-alert>

                    <a-alert
                        v-if="current.items_missing_cost > 0"
                        type="warning"
                        show-icon
                        :message="`${current.items_missing_cost} sold item(s) have no cost price, so cost of goods is understated and profit looks higher than it is.`"
                    />
                    <a-alert
                        v-if="!overview.expenses_recorded"
                        type="info"
                        show-icon
                        message="No expenses recorded yet, so net profit only counts product costs. Record rent, salaries, utilities and other running costs under Expenses & income."
                    />

                    <!-- Headline numbers -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4" data-testid="finance-kpis">
                        <MetricCard label="Revenue (sales less VAT)" :value="current.revenue" :change="changes.revenue" :hint="`${current.sales_count} sales`">
                            <template #action>
                                <ExplainButton topic="metric" metric="Revenue" :filters="props.filters" :ai-enabled="aiEnabled" title="Revenue" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="Gross profit" :value="current.gross_profit" :change="changes.gross_profit"
                            :hint="`After product costs of ${formattedTotal(current.cogs)} (${current.gross_margin_pct}% kept)`">
                            <template #action>
                                <ExplainButton topic="metric" metric="Gross profit" :filters="props.filters" :ai-enabled="aiEnabled" title="Gross profit" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="Operating expenses" :value="current.operating_expenses" :change="changes.operating_expenses" :up-is-good="false"
                            :hint="biggestOperating ? `Biggest: ${biggestOperating.name}` : 'Rent, salaries, utilities…'">
                            <template #action>
                                <ExplainButton topic="expenses" :filters="props.filters" :ai-enabled="aiEnabled" title="Your expenses" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="Net profit" :value="current.net_profit" :change="changes.net_profit"
                            :hint="`You keep ${current.net_margin_pct}% of each sale after all costs`">
                            <template #action>
                                <ExplainButton topic="metric" metric="Net profit" :filters="props.filters" :ai-enabled="aiEnabled" title="Net profit" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="Money received" :value="moneyIn.total_received" :change="changes.total_received"
                            hint="Paid by customers this period, including credit payments">
                            <template #action>
                                <ExplainButton topic="cash_flow" :filters="props.filters" :ai-enabled="aiEnabled" title="Your cash flow" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="Customers owe you" :value="ar.outstanding" :change="positionChange.receivables" :up-is-good="false" :hint="`${formattedTotal(ar.overdue)} overdue · ${ar.customers_owing} customer(s)`">
                            <template #action>
                                <ExplainButton topic="receivables" :filters="props.filters" :ai-enabled="aiEnabled" title="Customer credit" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="You owe suppliers" :value="ap.outstanding" :change="positionChange.payables" :up-is-good="false"
                            :hint="`${formattedTotal(ap.overdue)} overdue · ${formattedTotal(ap.due_within_7_days)} due in 7 days`">
                            <template #action>
                                <ExplainButton topic="payables" :filters="props.filters" :ai-enabled="aiEnabled" title="Supplier bills" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="Inventory value (today)" :value="overview.inventory_value" :change="overview.location_id ? null : positionChange.inventory_value" :up-is-good="false" hint="What your stock on hand cost">
                            <template #action>
                                <ExplainButton topic="metric" metric="Inventory value" :filters="props.filters" :ai-enabled="aiEnabled" title="Inventory value" label="Explain" link />
                            </template>
                        </MetricCard>
                        <MetricCard label="Cash balance (today)" :value="cashBalance.total" :change="overview.location_id ? null : positionChange.cash_balance"
                            :hint="`Drawers ${formattedTotal(cashBalance.drawer)} · Bank & e-wallets ${formattedTotal(cashBalance.accounts)} (as last entered)`">
                            <template #action>
                                <ExplainButton topic="balance_sheet" :filters="props.filters" :ai-enabled="aiEnabled" title="Your financial position" label="Explain" link />
                            </template>
                        </MetricCard>
                    </div>

                    <AskPanel :ai-enabled="aiEnabled" :suggestions="suggestedQuestions" :location-id="props.filters.location_id" />

                    <!-- Health check -->
                    <section class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-gray-900">Business health</h2>
                            <ExplainButton topic="health" :filters="props.filters" :ai-enabled="aiEnabled"
                                label="What needs my attention?" title="What needs your attention" />
                        </div>
                        <HealthSummary :items="health" />
                    </section>

                    <!-- Suggestions -->
                    <section class="space-y-3">
                        <h2 class="text-base font-semibold text-gray-900">Suggestions</h2>
                        <div v-if="!recommendations.length" class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">
                            Nothing stands out for this period.
                        </div>
                        <ol v-else class="space-y-2" data-testid="finance-recommendations">
                            <li v-for="(rec, i) in recommendations" :key="rec.key" class="flex gap-3 rounded-lg border border-gray-200 bg-white p-4">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">{{ i + 1 }}</span>
                                <div>
                                    <p class="font-medium text-gray-900">{{ rec.title }}</p>
                                    <p class="text-sm text-gray-600">{{ rec.detail }}</p>
                                </div>
                            </li>
                        </ol>
                        <p class="text-xs text-gray-500">Suggestions are based on your POS records and are not professional financial advice.</p>
                    </section>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 lg:col-span-2">
                            <h2 class="text-base font-semibold text-gray-900">Last 6 months</h2>
                            <VueApexCharts type="line" height="280" :options="chartOptions" :series="chartSeries" />
                        </section>
                        <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                            <h2 class="text-base font-semibold text-gray-900">How customers paid</h2>
                            <div v-if="!moneyInRows.length" class="text-sm text-gray-500">No payments this period.</div>
                            <div v-for="row in moneyInRows" :key="row.label" class="flex justify-between text-sm">
                                <span class="text-gray-600">{{ row.label }}</span>
                                <span class="font-medium text-gray-900">{{ formattedTotal(row.value) }}</span>
                            </div>
                            <div v-if="moneyIn.sold_on_credit > 0" class="flex justify-between border-t border-gray-100 pt-2 text-sm">
                                <span class="text-gray-500">Sold on credit (not paid yet)</span>
                                <span class="text-gray-500">{{ formattedTotal(moneyIn.sold_on_credit) }}</span>
                            </div>
                        </section>
                    </div>

                    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                        <section class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900">Slow-moving stock</h2>
                            <a-table :columns="slowColumns" :data-source="overview.products.slow_movers" :pagination="false"
                                row-key="product_id" size="small" bordered class="bg-white" :scroll="{ x: 380 }" data-testid="slow-movers"
                                :locale="{ emptyText: 'Nothing is sitting on the shelf too long.' }" />
                            <p class="text-xs text-gray-500">More than 90 days of stock at the last 30 days' pace, or not sold in 30 days.</p>
                        </section>
                        <section class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900">Best sellers</h2>
                            <a-table :columns="productColumns" :data-source="overview.products.best_sellers" :pagination="false"
                                row-key="product_id" size="small" bordered class="bg-white" :scroll="{ x: 420 }" />
                        </section>
                        <section class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900">Lowest profit margins</h2>
                            <a-table :columns="productColumns" :data-source="overview.products.low_margin" :pagination="false"
                                row-key="product_id" size="small" bordered class="bg-white" :scroll="{ x: 420 }" />
                            <p class="text-xs text-gray-500">Margins use line totals, which may include VAT, so treat them as a guide.</p>
                        </section>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
