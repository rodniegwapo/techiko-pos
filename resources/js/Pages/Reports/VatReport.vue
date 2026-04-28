<script setup>
import { computed, ref, watch } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { IconPrinter } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import SimplePrintTable from "@/Components/Reports/SimplePrintTable.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

const page = usePage();
const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    summary: {
        type: Object,
        default: () => ({
            total_vat: 0,
            gross_sales: 0,
            sales_count: 0,
        }),
    },
    locations: {
        type: Array,
        default: () => [],
    },
    transactions: {
        type: Object,
        default: () => ({
            data: [],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 100,
                total: 0,
                from: null,
                to: null,
            },
        }),
    },
});

const domainName =
    page.props.currentDomain?.name ?? page.props.domain?.name ?? "Organization";

const dateRangeLocal = ref([
    props.filters?.start_date ? dayjs(props.filters.start_date) : null,
    props.filters?.end_date ? dayjs(props.filters.end_date) : null,
]);
const locationIdLocal = ref(props.filters?.location_id ?? null);

watch(
    () => props.filters,
    (f) => {
        dateRangeLocal.value = [
            f?.start_date ? dayjs(f.start_date) : null,
            f?.end_date ? dayjs(f.end_date) : null,
        ];
        locationIdLocal.value = f?.location_id ?? null;
    },
    { deep: true },
);

const txMeta = computed(() => props.transactions?.meta ?? {});
const txData = computed(() => props.transactions?.data ?? []);

const locationFilterLabel = computed(() => {
    const id = props.filters?.location_id;
    if (!id) return "All locations";
    const loc = props.locations?.find((l) => l.id === id);
    return loc?.name ?? `Location #${id}`;
});

const periodLabel = computed(() => {
    const a = props.filters?.start_date;
    const b = props.filters?.end_date;
    if (!a || !b) return "";
    return `${a} to ${b}`;
});

const pageSubtotals = computed(() => {
    const rows = txData.value;
    let net = 0;
    let vat = 0;
    let gross = 0;
    for (const r of rows) {
        net += Number(r.taxable_net) || 0;
        vat += Number(r.tax_amount) || 0;
        gross += Number(r.grand_total) || 0;
    }
    return {
        taxable_net: net,
        tax_amount: vat,
        grand_total: gross,
    };
});

function applyFilters() {
    const start = dateRangeLocal.value?.[0]?.format?.("YYYY-MM-DD");
    const end = dateRangeLocal.value?.[1]?.format?.("YYYY-MM-DD");
    router.get(
        getRoute("vat-report.index"),
        {
            start_date: start,
            end_date: end,
            location_id: locationIdLocal.value || undefined,
            page: 1,
        },
        { preserveState: true, replace: true },
    );
}

function goToPage(pageNum) {
    router.get(
        getRoute("vat-report.index"),
        {
            start_date: props.filters.start_date,
            end_date: props.filters.end_date,
            location_id: props.filters.location_id || undefined,
            page: pageNum,
        },
        { preserveState: true, replace: true },
    );
}

/** Print-only: omit customer / location / payment (still in subtitle via location filter label) */
const printColumns = [
    { key: "transaction_date_display", title: "Date" },
    { key: "reference", title: "Invoice / ref" },
    { key: "taxable_net", title: "Taxable (net)" },
    { key: "tax_amount", title: "VAT" },
    { key: "grand_total", title: "Grand total" },
];

const estimatedTaxableNet = computed(() => {
    const gross = Number(props.summary?.gross_sales) || 0;
    const vat = Number(props.summary?.total_vat) || 0;
    return gross - vat;
});

const paginationPrintNote = computed(() => {
    const m = txMeta.value;
    if (!m.last_page || m.last_page <= 1) return "";
    return `Showing page ${m.current_page} of ${m.last_page}; line items below are paginated.`;
});

/** vue3-print-nb reads `document.getElementById(id)`; bind `v-print` to a native node (not `a-button`) so the click listener always attaches */
const vatPrintOptions = {
    id: "vat-print-area",
    popTitle: "VAT report",
};

const columns = [
    {
        title: "Date",
        dataIndex: "transaction_date_display",
        key: "transaction_date_display",
        width: 150,
    },
    {
        title: "Invoice / ref",
        dataIndex: "reference",
        key: "reference",
        width: 120,
    },
    {
        title: "Customer",
        dataIndex: "customer_name",
        key: "customer_name",
        ellipsis: true,
    },
    {
        title: "Location",
        dataIndex: "location_name",
        key: "location_name",
        width: 130,
    },
    {
        title: "Payment",
        dataIndex: "payment_method",
        key: "payment_method",
        width: 90,
    },
    {
        title: "Taxable (net)",
        dataIndex: "taxable_net",
        key: "taxable_net",
        align: "right",
        customRender: ({ text }) => formattedTotal(text),
    },
    {
        title: "VAT",
        dataIndex: "tax_amount",
        key: "tax_amount",
        align: "right",
        customRender: ({ text }) => formattedTotal(text),
    },
    {
        title: "Grand total",
        dataIndex: "grand_total",
        key: "grand_total",
        align: "right",
        customRender: ({ text }) => formattedTotal(text),
    },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="VAT report" />
        <ContentHeader
            class="mb-6 no-print"
            :title="`VAT report — ${domainName}`"
        />
        <ContentLayout title="Output VAT (paid sales)">
            <template #table>
                <div
                    class="vat-report-print-root px-6 pt-2 pb-8 space-y-6 max-w-6xl"
                >
                    <p class="text-sm text-gray-600 no-print">
                        Totals use
                        <strong>paid</strong> sales only, filtered by
                        <strong>transaction date</strong>. This supports
                        reconciliation and filing prep; it is not a substitute
                        for full input VAT unless you track purchases
                        separately.
                    </p>

                    <div class="flex flex-wrap items-end gap-4 no-print">
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-gray-600"
                                >Date range</span
                            >
                            <a-range-picker
                                v-model:value="dateRangeLocal"
                                format="YYYY-MM-DD"
                                class="min-w-[260px]"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-gray-600">Location</span>
                            <a-select
                                v-model:value="locationIdLocal"
                                allow-clear
                                placeholder="All locations"
                                class="min-w-[200px]"
                                :options="
                                    (locations || []).map((l) => ({
                                        value: l.id,
                                        label: l.name,
                                    }))
                                "
                            />
                        </div>
                        <a-button type="primary" @click="applyFilters">
                            Apply
                        </a-button>
                        <span
                            class="ml-auto inline-flex"
                            v-print="vatPrintOptions"
                        >
                            <a-button
                                type="default"
                                class="flex items-center gap-2"
                            >
                                <template #icon>
                                    <IconPrinter :size="20" />
                                </template>
                                Print
                            </a-button>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div
                            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div class="text-xs font-medium text-gray-500">
                                Total output VAT
                            </div>
                            <div
                                class="mt-1 text-xl font-semibold text-gray-900"
                            >
                                {{ formattedTotal(summary.total_vat) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div class="text-xs font-medium text-gray-500">
                                Gross sales (paid)
                            </div>
                            <div
                                class="mt-1 text-xl font-semibold text-gray-900"
                            >
                                {{ formattedTotal(summary.gross_sales) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div class="text-xs font-medium text-gray-500">
                                Number of sales
                            </div>
                            <div
                                class="mt-1 text-xl font-semibold text-gray-900"
                            >
                                {{ summary.sales_count }}
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <h2 class="text-base font-semibold text-gray-900">
                                Transaction detail
                            </h2>
                            <p
                                v-if="txMeta.total > txMeta.per_page"
                                class="text-xs text-gray-500 no-print"
                            >
                                Showing
                                {{ txMeta.from ?? 0 }}–{{ txMeta.to ?? 0 }} of
                                {{ txMeta.total }} (summary cards are for all
                                {{ summary.sales_count }} sales in range).
                            </p>
                        </div>

                        <a-table
                            :columns="columns"
                            :data-source="txData"
                            :pagination="false"
                            :row-key="(r) => r.id"
                            size="small"
                            bordered
                            class="bg-white"
                        />

                        <div
                            v-if="txData.length"
                            class="flex flex-wrap justify-end gap-6 text-sm border border-t-0 border-gray-200 rounded-b bg-gray-50 px-3 py-2"
                        >
                            <span
                                ><strong>This page</strong> — Taxable (net):
                                {{
                                    formattedTotal(pageSubtotals.taxable_net)
                                }}</span
                            >
                            <span
                                >VAT:
                                {{
                                    formattedTotal(pageSubtotals.tax_amount)
                                }}</span
                            >
                            <span
                                >Grand:
                                {{
                                    formattedTotal(pageSubtotals.grand_total)
                                }}</span
                            >
                        </div>

                        <div
                            v-if="txMeta.last_page > 1"
                            class="flex justify-end no-print"
                        >
                            <a-pagination
                                :current="txMeta.current_page"
                                :page-size="txMeta.per_page"
                                :total="txMeta.total"
                                show-less-items
                                @change="goToPage"
                            />
                        </div>
                    </div>

                    <!-- Same pattern as vue3-print-nb samples: outer hidden + inner #id (avoids fixed/off-screen quirks; getElementById still finds the node) -->
                    <div class="hidden" aria-hidden="true">
                        <div
                            id="vat-print-area"
                            class="absolute left-0 top-0 w-[min(900px,100vw)] bg-white text-gray-900"
                        >
                            <SimplePrintTable
                                title="VAT transaction register"
                                :subtitle="`${domainName} · Period: ${periodLabel} · ${locationFilterLabel} · Paid sales only (transaction date).`"
                                :columns="printColumns"
                            >
                                <tr
                                    v-for="row in txData"
                                    :key="row.id"
                                    class="odd:bg-white even:bg-gray-50"
                                >
                                    <td
                                        class="border border-gray-300 px-2 py-1"
                                    >
                                        {{ row.transaction_date_display }}
                                    </td>
                                    <td
                                        class="border border-gray-300 px-2 py-1"
                                    >
                                        {{ row.reference }}
                                    </td>
                                    <td
                                        class="border border-gray-300 px-2 py-1 text-right tabular-nums"
                                    >
                                        {{ formattedTotal(row.taxable_net) }}
                                    </td>
                                    <td
                                        class="border border-gray-300 px-2 py-1 text-right tabular-nums"
                                    >
                                        {{ formattedTotal(row.tax_amount) }}
                                    </td>
                                    <td
                                        class="border border-gray-300 px-2 py-1 text-right tabular-nums"
                                    >
                                        {{ formattedTotal(row.grand_total) }}
                                    </td>
                                </tr>
                                <template #footer>
                                    <tr v-if="paginationPrintNote">
                                        <td
                                            colspan="5"
                                            class="border border-gray-300 bg-gray-50 px-2 py-1.5 text-xs italic text-gray-600"
                                        >
                                            {{ paginationPrintNote }}
                                        </td>
                                    </tr>
                                    <tr
                                        class="bg-gray-100 font-semibold text-gray-900"
                                    >
                                        <td
                                            colspan="2"
                                            class="border border-gray-300 px-2 py-2 align-top"
                                        >
                                            Period totals (full selected period)
                                            <div
                                                class="mt-1 text-xs font-normal text-gray-700"
                                            >
                                                Sales count:
                                                {{ summary.sales_count }}
                                            </div>
                                        </td>
                                        <td
                                            class="border border-gray-300 px-2 py-2 text-right tabular-nums align-top"
                                        >
                                            <span
                                                class="block text-xs font-normal text-gray-600"
                                                >Est. taxable (net)</span
                                            >
                                            {{
                                                formattedTotal(
                                                    estimatedTaxableNet,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="border border-gray-300 px-2 py-2 text-right tabular-nums align-top"
                                        >
                                            <span
                                                class="block text-xs font-normal text-gray-600"
                                                >Total output VAT</span
                                            >
                                            {{
                                                formattedTotal(
                                                    summary.total_vat,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="border border-gray-300 px-2 py-2 text-right tabular-nums align-top"
                                        >
                                            <span
                                                class="block text-xs font-normal text-gray-600"
                                                >Gross sales</span
                                            >
                                            {{
                                                formattedTotal(
                                                    summary.gross_sales,
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </template>
                            </SimplePrintTable>
                        </div>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>

<style scoped>
@media print {
    .no-print {
        display: none !important;
    }
}
</style>

<style>
@media print {
    .ant-layout-sider {
        display: none !important;
    }
    .ant-layout-content {
        margin-left: 0 !important;
    }
}
</style>
