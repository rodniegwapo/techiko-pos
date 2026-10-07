<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import dayjs from "dayjs";
import {
    IconReceipt2,
    IconCash,
    IconDiscount2,
    IconReceiptTax,
    IconCoins,
    IconReceiptOff,
    IconTrendingUp,
    IconCalendarEvent,
} from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import SaleDetailDrawer from "./components/SaleDetailDrawer.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useFilters, toLabel } from "@/Composables/useFilters";

const props = defineProps({
    items: { type: Object, default: () => ({ data: [], meta: {} }) },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    restrictedToOwnSales: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
});

const { getRoute } = useDomainRoutes();
const { spinning } = useGlobalVariables();
const { formattedTotal } = useHelpers();
const { hasPermission } = usePermissionsV2();

const capitalize = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

/*** === FILTER STATE (seeded from the server's resolved filters) === ***/
// Keys differ from the query param names on purpose: useFilters seeds any key it finds in the URL
// as a string, which would not match the numeric ids the selects use.
const dateRange = ref(
    props.filters.start_date
        ? [dayjs(props.filters.start_date), dayjs(props.filters.end_date)]
        : null,
);
const location = ref(props.filters.location_id ?? null);
const cashier = ref(props.restrictedToOwnSales ? null : props.filters.user_id ?? null);
const payment = ref(props.filters.payment_method ?? null);
const status = ref(props.filters.payment_status ?? null);
const search = ref(props.filters.search ?? "");

const locationOptions = computed(() =>
    (props.options.locations || []).map((l) => ({ value: l.id, label: l.name })),
);
const cashierOptions = computed(() =>
    (props.options.cashiers || []).map((u) => ({ value: u.id, label: u.name })),
);
const paymentOptions = computed(() =>
    (props.options.payment_methods || []).map((m) => ({ value: m, label: capitalize(m) })),
);
const statusOptions = computed(() =>
    (props.options.statuses || []).map((s) => ({ value: s, label: capitalize(s) })),
);

const queryParams = (extra = {}) => ({
    start_date: dateRange.value?.[0] ? dayjs(dateRange.value[0]).format("YYYY-MM-DD") : undefined,
    end_date: dateRange.value?.[1] ? dayjs(dateRange.value[1]).format("YYYY-MM-DD") : undefined,
    location_id: location.value || undefined,
    user_id: cashier.value || undefined,
    payment_method: payment.value || undefined,
    payment_status: status.value || undefined,
    search: search.value || undefined,
    per_page: props.items.meta?.per_page,
    ...extra,
});

const load = (extra = {}) => {
    router.get(getRoute("sales-history.index"), queryParams({ page: 1, ...extra }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

watchDebounced(search, () => load(), { debounce: 400 });

const rangeLabel = (v) =>
    Array.isArray(v) && v[0] && v[1]
        ? `${dayjs(v[0]).format("MMM D, YYYY")} – ${dayjs(v[1]).format("MMM D, YYYY")}`
        : null;

const filterConfigs = [
    { label: "Date", key: "date", ref: dateRange, getLabel: rangeLabel },
    ...(props.restrictedToOwnSales
        ? []
        : [
              { label: "Location", key: "location", ref: location, getLabel: toLabel(locationOptions) },
              { label: "Cashier", key: "cashier", ref: cashier, getLabel: toLabel(cashierOptions) },
          ]),
    { label: "Payment", key: "payment", ref: payment, getLabel: toLabel(paymentOptions) },
    { label: "Status", key: "status", ref: status, getLabel: toLabel(statusOptions) },
];

const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems: () => load(),
    configs: filterConfigs,
});

const filtersConfig = computed(() => [
    { key: "date", label: "Date", type: "range" },
    ...(props.restrictedToOwnSales
        ? []
        : [
              { key: "location", label: "Location", type: "select", options: locationOptions.value },
              { key: "cashier", label: "Cashier", type: "select", options: cashierOptions.value },
          ]),
    { key: "payment", label: "Payment method", type: "select", options: paymentOptions.value },
    { key: "status", label: "Status", type: "select", options: statusOptions.value },
]);

/** The period the server actually applied (it falls back to today when no date is chosen). */
const periodLabel = computed(() => {
    const start = dayjs(props.filters.start_date);
    const end = dayjs(props.filters.end_date);
    if (!start.isValid()) return "";
    if (start.isSame(end, "day")) {
        return start.isSame(dayjs(), "day") ? `Today, ${start.format("MMM D, YYYY")}` : start.format("ddd, MMM D, YYYY");
    }
    return `${start.format("MMM D, YYYY")} – ${end.format("MMM D, YYYY")}`;
});

/*** === TABLE === ***/
const pagination = computed(() => ({
    total: props.items.meta?.total ?? 0,
    current: props.items.meta?.current_page ?? 1,
    pageSize: props.items.meta?.per_page ?? 20,
    showSizeChanger: true,
    pageSizeOptions: ["20", "50", "100"],
    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} sales`,
}));

const handleTableChange = (p) => load({ page: p.current, per_page: p.pageSize });

const statusColor = (s) => ({ paid: "green", partial: "orange", refunded: "red" })[s] || "default";

const columns = [
    { title: "Date / time", dataIndex: "transaction_date_display", key: "date", width: 170 },
    { title: "Invoice #", dataIndex: "invoice_number", key: "invoice" },
    { title: "Cashier", dataIndex: "cashier_name", key: "cashier" },
    { title: "Customer", dataIndex: "customer_name", key: "customer" },
    { title: "Location", dataIndex: "location_name", key: "location" },
    { title: "Payment", dataIndex: "payment_method", key: "payment" },
    { title: "Status", dataIndex: "payment_status", key: "status" },
    { title: "Grand total", dataIndex: "grand_total", key: "grand_total", align: "right" },
];

/*** === DETAIL DRAWER === ***/
const selectedSaleId = ref(null);
const customRow = (record) => ({
    onClick: () => (selectedSaleId.value = record.id),
    class: "cursor-pointer",
});

/*** === EXPORT === ***/
const exportUrl = computed(() => {
    const base = getRoute("sales-history.export");
    if (!base || base === "#") return "#";
    const params = Object.fromEntries(
        Object.entries(queryParams()).filter(([k, v]) => v !== undefined && v !== "" && k !== "per_page"),
    );
    return `${base}?${new URLSearchParams(params).toString()}`;
});

/*** === SUMMARY CARDS === ***/
const percent = (part, whole) => (whole > 0 ? `${((part / whole) * 100).toFixed(1)}%` : "0%");

const summaryCards = computed(() => {
    const s = props.summary;
    const count = s.sales_count ?? 0;
    const gross = s.gross ?? 0;

    return [
        {
            key: "sales",
            label: "Sales",
            value: count.toLocaleString(),
            hint: count ? `Avg ${formattedTotal((s.net ?? 0) / count)} per sale` : "No sales in this period",
            icon: IconReceipt2,
            tone: "bg-blue-50 text-blue-600",
        },
        {
            key: "gross",
            label: "Gross sales",
            value: formattedTotal(gross),
            hint: "Before discounts",
            icon: IconCash,
            tone: "bg-teal-50 text-teal-600",
        },
        {
            key: "discounts",
            label: "Discounts",
            value: formattedTotal(s.discounts ?? 0),
            hint: `${percent(s.discounts ?? 0, gross)} of gross`,
            icon: IconDiscount2,
            tone: "bg-amber-50 text-amber-600",
        },
        {
            key: "vat",
            label: "VAT",
            value: formattedTotal(s.vat ?? 0),
            hint: "Output tax on these sales",
            icon: IconReceiptTax,
            tone: "bg-purple-50 text-purple-600",
        },
        {
            key: "net",
            label: "Net total",
            value: formattedTotal(s.net ?? 0),
            hint: "What customers paid",
            icon: IconCoins,
            tone: "bg-green-50 text-green-700",
            highlight: true,
        },
        {
            key: "profit",
            label: "Profit",
            value: formattedTotal(s.profit ?? 0),
            // Lines without a cost count as zero cost, so say so rather than show an inflated margin.
            hint: s.items_missing_cost
                ? `${s.items_missing_cost.toLocaleString()} item(s) missing cost`
                : `${percent(s.profit ?? 0, (s.net ?? 0) - (s.vat ?? 0))} margin, ex-VAT`,
            icon: IconTrendingUp,
            tone: "bg-emerald-50 text-emerald-600",
        },
        {
            key: "voids",
            label: "Sales with voids",
            value: (s.sales_with_voids ?? 0).toLocaleString(),
            hint: `${percent(s.sales_with_voids ?? 0, count)} of sales`,
            icon: IconReceiptOff,
            tone: "bg-red-50 text-red-600",
        },
        // The server leaves profit out for staff who only see their own sales.
    ].filter((card) => card.key !== "profit" || s.profit !== undefined);
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Sales History" />
        <ContentHeader class="mb-4 md:mb-6" title="Sales History" />
        <ContentLayout
            :title="restrictedToOwnSales ? 'My sales' : 'All sales'"
            filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
        >
            <template #filters>
                <refresh-button :loading="spinning" @click="load({ page: pagination.current })" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Invoice # or customer"
                    allow-clear
                    style="width: 260px; max-width: 100%"
                />
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
                <a
                    v-if="hasPermission('sales-history.export')"
                    :href="exportUrl"
                    data-testid="sales-history-export"
                >
                    <a-button>Export CSV</a-button>
                </a>
            </template>

            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="() => Object.keys(filters).forEach((k) => (filters[k] = null))"
                />
            </template>

            <template #table>
                <div class="space-y-4 px-4 pb-6 md:px-6">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <IconCalendarEvent :size="18" class="text-gray-400" />
                        <span data-testid="sales-history-period">{{ periodLabel }}</span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <div
                            v-for="card in summaryCards"
                            :key="card.key"
                            :data-testid="`summary-${card.key}`"
                            class="flex items-start gap-3 rounded-xl border bg-white p-4 shadow-sm transition-shadow hover:shadow-md"
                            :class="card.highlight ? 'border-green-200 ring-1 ring-green-100' : 'border-gray-200'"
                        >
                            <div
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg"
                                :class="card.tone"
                            >
                                <component :is="card.icon" :size="24" :stroke-width="1.75" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">
                                    {{ card.label }}
                                </div>
                                <div
                                    class="mt-0.5 truncate text-xl font-semibold"
                                    :class="card.highlight ? 'text-green-700' : 'text-gray-900'"
                                >
                                    {{ card.value }}
                                </div>
                                <div class="mt-0.5 truncate text-xs text-gray-500">{{ card.hint }}</div>
                            </div>
                        </div>
                    </div>

                    <a-table
                        :columns="columns"
                        :data-source="items.data"
                        :pagination="pagination"
                        :row-key="(r) => r.id"
                        :custom-row="customRow"
                        :loading="spinning"
                        :scroll="{ x: 900 }"
                        size="middle"
                        data-testid="sales-history-table"
                        @change="handleTableChange"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'invoice'">
                                <span class="font-medium text-blue-600">{{ record.invoice_number || `#${record.id}` }}</span>
                                <a-tag v-if="record.voided_items_count" color="red" class="ml-2">
                                    {{ record.voided_items_count }} voided
                                </a-tag>
                            </template>
                            <template v-else-if="column.key === 'payment'">
                                {{ capitalize(record.payment_method) }}
                                <span v-if="record.payment_card_type" class="text-gray-500"> · {{ record.payment_card_type }}</span>
                            </template>
                            <template v-else-if="column.key === 'status'">
                                <a-tag :color="statusColor(record.payment_status)">{{ record.payment_status }}</a-tag>
                                <a-tag v-if="record.is_credit_sale" color="purple">credit</a-tag>
                            </template>
                            <template v-else-if="column.key === 'grand_total'">
                                {{ formattedTotal(record.grand_total) }}
                            </template>
                            <template v-else-if="column.key === 'location'">
                                {{ record.location_name || "—" }}
                            </template>
                        </template>
                    </a-table>
                </div>
            </template>
        </ContentLayout>

        <SaleDetailDrawer
            :sale-id="selectedSaleId"
            :business-name="domainName"
            @close="selectedSaleId = null"
        />
    </AuthenticatedLayout>
</template>
