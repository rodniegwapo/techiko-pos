<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import dayjs from "dayjs";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import SaleDetailDrawer from "./components/SaleDetailDrawer.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

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

/*** === FILTER STATE (seeded from the server's resolved filters) === ***/
const dateRange = ref([
    dayjs(props.filters.start_date),
    dayjs(props.filters.end_date),
]);
const locationId = ref(props.filters.location_id ?? undefined);
const userId = ref(props.restrictedToOwnSales ? undefined : props.filters.user_id ?? undefined);
const paymentMethod = ref(props.filters.payment_method ?? undefined);
const paymentStatus = ref(props.filters.payment_status ?? undefined);
const search = ref(props.filters.search ?? "");

const queryParams = (extra = {}) => ({
    start_date: dateRange.value?.[0]?.format("YYYY-MM-DD"),
    end_date: dateRange.value?.[1]?.format("YYYY-MM-DD"),
    location_id: locationId.value || undefined,
    user_id: userId.value || undefined,
    payment_method: paymentMethod.value || undefined,
    payment_status: paymentStatus.value || undefined,
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

const setToday = () => {
    dateRange.value = [dayjs(), dayjs()];
    load();
};

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

const statusColor = (status) =>
    ({ paid: "green", partial: "orange", refunded: "red" })[status] || "default";

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

const summaryCards = computed(() => [
    { label: "Sales", value: props.summary.sales_count ?? 0 },
    { label: "Gross (before discounts)", value: formattedTotal(props.summary.gross ?? 0) },
    { label: "Discounts", value: formattedTotal(props.summary.discounts ?? 0) },
    { label: "VAT", value: formattedTotal(props.summary.vat ?? 0) },
    { label: "Net total", value: formattedTotal(props.summary.net ?? 0) },
    { label: "Sales with voids", value: props.summary.sales_with_voids ?? 0 },
]);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Sales History" />
        <ContentHeader class="mb-4 md:mb-6" title="Sales History" />
        <ContentLayout
            :title="restrictedToOwnSales ? 'My sales' : 'All sales'"
            filter-class="flex flex-wrap items-end justify-end gap-2 w-full min-w-0"
        >
            <template #filters>
                <refresh-button :loading="spinning" @click="load({ page: pagination.current })" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Invoice # or customer"
                    allow-clear
                    style="width: 220px; max-width: 100%"
                />
                <a-range-picker
                    v-model:value="dateRange"
                    format="YYYY-MM-DD"
                    :allow-clear="false"
                    style="width: 270px; max-width: 100%"
                    @change="load()"
                />
                <a-button @click="setToday">Today</a-button>
                <a-select
                    v-if="!restrictedToOwnSales && options.locations?.length"
                    v-model:value="locationId"
                    allow-clear
                    placeholder="All locations"
                    style="width: 170px; max-width: 100%"
                    :options="options.locations.map((l) => ({ value: l.id, label: l.name }))"
                    @change="load()"
                />
                <a-select
                    v-if="!restrictedToOwnSales"
                    v-model:value="userId"
                    allow-clear
                    show-search
                    option-filter-prop="label"
                    placeholder="All cashiers"
                    style="width: 170px; max-width: 100%"
                    :options="(options.cashiers || []).map((u) => ({ value: u.id, label: u.name }))"
                    @change="load()"
                />
                <a-select
                    v-model:value="paymentMethod"
                    allow-clear
                    placeholder="Any payment"
                    style="width: 140px; max-width: 100%"
                    :options="(options.payment_methods || []).map((m) => ({ value: m, label: m }))"
                    @change="load()"
                />
                <a-select
                    v-model:value="paymentStatus"
                    allow-clear
                    placeholder="Any status"
                    style="width: 130px; max-width: 100%"
                    :options="(options.statuses || []).map((s) => ({ value: s, label: s }))"
                    @change="load()"
                />
                <a
                    v-if="hasPermission('sales-history.export')"
                    :href="exportUrl"
                    data-testid="sales-history-export"
                >
                    <a-button>Export CSV</a-button>
                </a>
            </template>

            <template #table>
                <div class="space-y-4 px-4 pb-6 md:px-6">
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        <div
                            v-for="card in summaryCards"
                            :key="card.label"
                            class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
                        >
                            <div class="text-xs font-medium text-gray-500">{{ card.label }}</div>
                            <div class="mt-1 text-lg font-semibold text-gray-900">{{ card.value }}</div>
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
                                {{ record.payment_method }}
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
