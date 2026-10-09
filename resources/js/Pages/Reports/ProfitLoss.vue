<script setup>
import { computed, reactive, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import VueApexCharts from "vue3-apexcharts";
import { IconPrinter, IconDownload, IconChevronDown, IconChevronRight, IconInfoCircle } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import SimplePrintTable from "@/Components/Reports/SimplePrintTable.vue";
import FinanceNav from "@/Pages/Finance/components/FinanceNav.vue";
import ExplainButton from "@/Pages/Finance/components/ExplainButton.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    periods: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    current: { type: Object, default: () => ({}) },
    previous: { type: Object, default: () => ({}) },
    locations: { type: Array, default: () => [] },
    canSeeAllStores: { type: Boolean, default: false },
    aiEnabled: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
});

const { getRoute } = useDomainRoutes();
const { spinning } = useGlobalVariables();
const { formattedTotal } = useHelpers();
const { hasPermission } = usePermissionsV2();

/*** === FILTERS (FilterDropdown, as on the other list pages) === ***/
// Keys differ from the query params on purpose: useFilters seeds keys it finds in the URL as strings.
const period = ref(props.filters.preset && props.filters.preset !== "custom" ? props.filters.preset : null);
const customRange = ref(
    props.filters.preset === "custom" ? [dayjs(props.filters.start_date), dayjs(props.filters.end_date)] : null,
);
const store = ref(props.filters.location_id ?? null);

const presetOptions = [
    { value: "this_month", label: "This month" },
    { value: "last_month", label: "Last month" },
    { value: "this_quarter", label: "This quarter" },
    { value: "this_year", label: "This year" },
];

const locationOptions = computed(() => props.locations.map((l) => ({ value: l.id, label: l.name })));

/** Picked dates win over a period; nothing picked is this month. */
const queryParams = () => {
    const range = customRange.value;
    const custom = Array.isArray(range) && range[0] && range[1];
    return {
        preset: custom ? "custom" : period.value || "this_month",
        start_date: custom ? dayjs(range[0]).format("YYYY-MM-DD") : undefined,
        end_date: custom ? dayjs(range[1]).format("YYYY-MM-DD") : undefined,
        location_id: props.canSeeAllStores ? store.value || undefined : props.filters.location_id || undefined,
    };
};

const rangeLabel = (v) =>
    Array.isArray(v) && v[0] && v[1] ? `${dayjs(v[0]).format("MMM D, YYYY")} – ${dayjs(v[1]).format("MMM D, YYYY")}` : null;

const filterConfigs = [
    { label: "Period", key: "period", ref: period, getLabel: toLabel(computed(() => presetOptions)) },
    { label: "Dates", key: "dates", ref: customRange, getLabel: rangeLabel },
    ...(props.canSeeAllStores ? [{ label: "Store", key: "store", ref: store, getLabel: toLabel(locationOptions) }] : []),
];

const { filters, activeFilters, handleClearSelectedFilter } = useFilters({ getItems: () => load(), configs: filterConfigs });

const filtersConfig = computed(() => [
    { key: "period", label: "Period", type: "select", options: presetOptions },
    { key: "dates", label: "Custom dates", type: "range" },
    ...(props.canSeeAllStores ? [{ key: "store", label: "Store", type: "select", options: locationOptions.value }] : []),
]);

const clearAll = () => Object.keys(filters.value).forEach((k) => (filters.value[k] = null));

/** The same period in the Finance pages' terms, for the tabs and the AI explanation. */
const financeFilters = computed(() => ({
    key: "custom",
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
    location_id: props.filters.location_id || null,
}));

const load = () => {
    router.get(getRoute("profit-loss.index"), queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

const exportUrl = computed(() => {
    const params = Object.fromEntries(Object.entries(queryParams()).filter(([, v]) => v !== undefined));
    return `${getRoute("profit-loss.export")}?${new URLSearchParams(params).toString()}`;
});

const storeLabel = computed(
    () => locationOptions.value.find((o) => o.value === (props.filters.location_id ?? null))?.label ?? "All stores",
);

/*** === STATEMENT === ***/
/** Lines that are taken away from sales: shown with a minus, and a rise is bad news. */
const isCost = (key) =>
    ["cogs", "inventory_losses", "expenses", "other_expenses"].includes(key) || /^(loss|expense|other-expense)-/.test(key);

const HINTS = {
    net_sales: "What customers paid on paid sales in this period, without VAT (VAT is collected for the government). Discounts are already taken off.",
    cogs: "What the items you sold cost you, using each item's cost when it was sold. Voided items are not counted.",
    inventory_losses: "Stock written off in approved stock adjustments (damaged, expired, stolen…), valued at cost.",
    gross_profit: "Net sales minus cost of goods and stock losses: what you make on the products themselves.",
    expenses: "Running costs recorded in Expenses, plus expense bills from suppliers: rent, salaries, utilities and so on.",
    operating_profit: "Gross profit minus running costs: what the shop itself earns.",
    other_income: "Money earned outside of sales, recorded under Other income.",
    other_expenses: "Costs outside the day-to-day running of the shop, like interest or one-off losses (expense categories of type “Other”).",
    net_profit: "What the business made after product costs and running costs. Income tax is not included.",
};

const collapsed = reactive({ inventory_losses: false, expenses: false, other_expenses: false });
const parentOf = (index) => {
    for (let i = index; i >= 0; i--) if (props.rows[i].type === "group") return props.rows[i].key;
    return null;
};
const visibleRows = computed(() =>
    props.rows.filter((row, i) => row.type !== "detail" || !collapsed[parentOf(i)]),
);
const hasChildren = (key) => props.rows.some((row, i) => row.type === "detail" && parentOf(i) === key);

const amount = (row, value) => (isCost(row.key) && value !== 0 ? `−${formattedTotal(value)}` : formattedTotal(value));

const changeClass = (row) => {
    if (row.change_percent === null || row.change_percent === 0) return "text-gray-400";
    const good = isCost(row.key) ? row.change_percent < 0 : row.change_percent > 0;
    return good ? "text-green-600" : "text-red-600";
};
const changeText = (row) =>
    row.change_percent === null ? "—" : `${row.change_percent > 0 ? "+" : ""}${row.change_percent}%`;

const rowClass = (row) =>
    ({
        subtotal: "bg-gray-50 font-semibold",
        total: "font-bold text-base border-t-2 border-gray-300",
        group: "font-medium",
        detail: "text-gray-500 text-xs",
    })[row.type] ?? "";

/*** === HEADLINE CARDS === ***/
const card = (key, label, marginKey) => {
    const row = props.rows.find((r) => r.key === key) ?? { current: 0, change_percent: null };
    return {
        key,
        label,
        value: row.current,
        change: row,
        margin: marginKey ? props.current[marginKey] : null,
    };
};
const cards = computed(() => [
    card("net_sales", "Net sales"),
    card("gross_profit", "Gross profit", "gross_margin_percent"),
    card("net_profit", "Net profit", "net_margin_percent"),
]);

/*** === EXPENSE CHART === ***/
const expenseLines = computed(() => props.current.expense_lines ?? []);
const chartSeries = computed(() => [{ name: "Expenses", data: expenseLines.value.map((l) => l.amount) }]);
const chartOptions = computed(() => ({
    chart: { type: "bar", toolbar: { show: false }, fontFamily: "inherit" },
    plotOptions: { bar: { horizontal: true, borderRadius: 3, barHeight: "60%" } },
    colors: ["#fb7185"],
    dataLabels: { enabled: false },
    xaxis: { categories: expenseLines.value.map((l) => l.label), labels: { formatter: (v) => formattedTotal(v) } },
    tooltip: { y: { formatter: (v) => formattedTotal(v) } },
    grid: { strokeDashArray: 3 },
}));

/*** === PRINT === ***/
const printOptions = { id: "pnl-print-area", popTitle: "Profit & Loss" };
const printColumns = [
    { key: "label", title: "Line" },
    { key: "current", title: props.periods.current },
    { key: "previous", title: props.periods.previous },
    { key: "change", title: "Change" },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Profit & Loss" />
        <ContentHeader class="mb-4 md:mb-6" title="Income statement" />
        <ContentLayout title="Profit & Loss" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="load()" />
                <ExplainButton topic="income_statement" :filters="financeFilters" :ai-enabled="aiEnabled"
                    label="Explain my profit" title="Your profit & loss in plain words" type="primary" size="middle" />
                <span v-print="printOptions">
                    <a-button class="flex items-center gap-1"><IconPrinter :size="16" /> Print</a-button>
                </span>
                <a v-if="hasPermission('profit-loss.export')" :href="exportUrl">
                    <a-button class="flex items-center gap-1"><IconDownload :size="16" /> Export CSV</a-button>
                </a>
                <FilterDropdown v-model="filters" :filters="filtersConfig" data-testid="pnl-filters" />
            </template>

            <template #activeFilters>
                <ActiveFilters :filters="activeFilters" @remove-filter="handleClearSelectedFilter" @clear-all="clearAll" />
            </template>

            <template #table>
                <a-spin :spinning="spinning">
                    <div class="space-y-4 px-4 pb-6 md:px-6">
                        <FinanceNav active="profit-loss.index" :filters="financeFilters" />
                        <div class="text-sm text-gray-600">
                            <strong>{{ periods.current }}</strong> compared with {{ periods.previous }} · {{ storeLabel }}
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <div
                                v-for="c in cards"
                                :key="c.key"
                                :data-testid="`pnl-card-${c.key}`"
                                class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                                :class="{ 'ring-1 ring-green-100': c.key === 'net_profit' && c.value > 0, 'ring-1 ring-red-100': c.key === 'net_profit' && c.value < 0 }"
                            >
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ c.label }}</div>
                                <div
                                    class="mt-1 text-2xl font-semibold"
                                    :class="c.key === 'net_sales' ? 'text-gray-900' : c.value < 0 ? 'text-red-600' : 'text-green-700'"
                                >
                                    {{ formattedTotal(c.value) }}
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-2 text-xs">
                                    <span v-if="c.margin !== null && c.margin !== undefined" class="text-gray-500">{{ c.margin }}% margin</span>
                                    <span :class="changeClass(c.change)">{{ changeText(c.change) }} vs {{ periods.previous }}</span>
                                </div>
                            </div>
                        </div>

                        <a-alert
                            v-if="current.memo?.items_missing_cost"
                            type="warning"
                            show-icon
                            :message="`${current.memo.items_missing_cost} sold item(s) have no cost set, so they count as free and profit looks higher than it really is. Set a cost on those products.`"
                        />
                        <a-alert
                            v-if="current.memo?.business_wide_expenses_excluded"
                            type="info"
                            show-icon
                            :message="`${formattedTotal(current.memo.business_wide_expenses_excluded)} of business-wide expenses (not tied to a store) are not included in this store's figures. Choose “All stores” to include them.`"
                        />

                        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                            <table class="w-full min-w-[560px] text-sm" data-testid="pnl-statement">
                                <thead>
                                    <tr class="bg-green-700 text-left text-white">
                                        <th class="px-4 py-2 font-medium"></th>
                                        <th class="px-4 py-2 text-right font-medium">{{ periods.current }}</th>
                                        <th class="px-4 py-2 text-right font-medium">{{ periods.previous }}</th>
                                        <th class="px-4 py-2 text-right font-medium">Change</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in visibleRows" :key="row.key" class="border-b border-gray-100" :class="rowClass(row)">
                                        <td class="px-4 py-2" :style="{ paddingLeft: `${16 + row.indent * 24}px` }">
                                            <span class="inline-flex items-center gap-1">
                                                <a
                                                    v-if="row.type === 'group' && hasChildren(row.key)"
                                                    class="text-gray-500"
                                                    @click="collapsed[row.key] = !collapsed[row.key]"
                                                >
                                                    <IconChevronRight v-if="collapsed[row.key]" :size="14" />
                                                    <IconChevronDown v-else :size="14" />
                                                </a>
                                                {{ row.label }}
                                                <a-tooltip v-if="HINTS[row.key]" :title="HINTS[row.key]">
                                                    <IconInfoCircle :size="14" class="text-gray-400" />
                                                </a-tooltip>
                                            </span>
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                            :class="{ 'text-red-600': ['gross_profit', 'net_profit'].includes(row.key) && row.current < 0 }"
                                        >
                                            {{ amount(row, row.current) }}
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ amount(row, row.previous) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums" :class="changeClass(row)">{{ changeText(row) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div class="rounded-xl border border-gray-200 bg-white p-4">
                                <div class="mb-2 text-sm font-semibold text-gray-900">Expenses by category</div>
                                <VueApexCharts
                                    v-if="expenseLines.length"
                                    type="bar"
                                    :height="Math.max(160, expenseLines.length * 36)"
                                    :options="chartOptions"
                                    :series="chartSeries"
                                />
                                <p v-else class="mb-0 text-sm text-gray-500">No expenses recorded in this period.</p>
                            </div>

                            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm">
                                <div class="mb-2 font-semibold text-gray-900">For reference</div>
                                <div class="flex justify-between py-1">
                                    <span>Paid sales</span><span>{{ (current.memo?.sales_count ?? 0).toLocaleString() }}</span>
                                </div>
                                <div class="flex justify-between py-1">
                                    <span>VAT collected <span class="text-xs text-gray-500">(owed to the BIR, not income)</span></span>
                                    <span>{{ formattedTotal(current.memo?.vat_collected ?? 0) }}</span>
                                </div>
                                <div class="flex justify-between py-1">
                                    <span>Discounts given <span class="text-xs text-gray-500">(already taken off net sales)</span></span>
                                    <span>{{ formattedTotal(current.memo?.discounts_given ?? 0) }}</span>
                                </div>
                                <p class="mb-0 mt-3 text-xs text-gray-500">
                                    Sales count on the day they are made, including credit sales. Costs use what each item cost when it
                                    was sold. Net profit is before income tax.
                                </p>
                            </div>
                        </div>
                    </div>
                </a-spin>

                <!-- Hidden print source for vue3-print-nb -->
                <div class="hidden" aria-hidden="true">
                    <div id="pnl-print-area" class="absolute left-0 top-0 w-[min(900px,100vw)] bg-white text-gray-900">
                        <SimplePrintTable
                            title="Profit & Loss"
                            :subtitle="`${domainName} · ${storeLabel} · ${periods.current} compared with ${periods.previous}`"
                            :columns="printColumns"
                        >
                            <tr v-for="row in rows" :key="row.key" :style="row.type === 'total' || row.type === 'subtotal' ? 'font-weight:600' : ''">
                                <td :style="{ paddingLeft: `${8 + row.indent * 20}px` }">{{ row.label }}</td>
                                <td style="text-align: right">{{ amount(row, row.current) }}</td>
                                <td style="text-align: right">{{ amount(row, row.previous) }}</td>
                                <td style="text-align: right">{{ changeText(row) }}</td>
                            </tr>
                        </SimplePrintTable>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
