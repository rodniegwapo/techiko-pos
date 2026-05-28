<script setup>
import { computed, ref, watch } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import axios from "axios";
import dayjs from "dayjs";
import { message } from "ant-design-vue";
import { IconPrinter } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import SimplePrintTable from "@/Components/Reports/SimplePrintTable.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

const page = usePage();
const isMdUp = useMediaQuery("(min-width: 768px)");
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

/** Same query params as screen filters; GET export streams full register (not paginated). */
const vatExportCsvUrl = computed(() => {
    const base = getRoute("vat-report.export");
    if (!base || base === "#") {
        return "#";
    }
    const q = new URLSearchParams();
    if (props.filters?.start_date) {
        q.set("start_date", props.filters.start_date);
    }
    if (props.filters?.end_date) {
        q.set("end_date", props.filters.end_date);
    }
    if (
        props.filters?.location_id != null &&
        props.filters?.location_id !== ""
    ) {
        q.set("location_id", String(props.filters.location_id));
    }
    const s = q.toString();
    return s ? `${base}?${s}` : base;
});

const exportingExcel = ref(false);

async function exportExcel() {
    const url = getRoute("vat-report.export-json");
    if (!url || url === "#") {
        message.error("Could not resolve export route.");
        return;
    }
    exportingExcel.value = true;
    try {
        const { data } = await axios.get(url, {
            params: {
                start_date: props.filters?.start_date ?? undefined,
                end_date: props.filters?.end_date ?? undefined,
                location_id: props.filters?.location_id ?? undefined,
            },
        });

        const ExcelJS = (await import("exceljs")).default;
        const workbook = new ExcelJS.Workbook();

        const register = workbook.addWorksheet("VAT register", {
            views: [{ state: "frozen", ySplit: 1 }],
        });
        register.columns = [
            { header: "ID", key: "id", width: 10 },
            {
                header: "Transaction datetime",
                key: "transaction_date_display",
                width: 20,
            },
            { header: "Invoice number", key: "invoice_number", width: 16 },
            { header: "Reference", key: "reference", width: 16 },
            { header: "Customer", key: "customer_name", width: 22 },
            { header: "Location", key: "location_name", width: 18 },
            { header: "Payment", key: "payment_method", width: 12 },
            { header: "Taxable (net)", key: "taxable_net", width: 14 },
            { header: "VAT", key: "tax_amount", width: 12 },
            { header: "Grand total", key: "grand_total", width: 14 },
        ];
        register.getRow(1).font = { bold: true };

        for (const row of data.transactions ?? []) {
            register.addRow({
                id: row.id,
                transaction_date_display: row.transaction_date_display,
                invoice_number: row.invoice_number ?? "",
                reference: row.reference,
                customer_name: row.customer_name,
                location_name: row.location_name,
                payment_method: row.payment_method,
                taxable_net: Number(row.taxable_net) || 0,
                tax_amount: Number(row.tax_amount) || 0,
                grand_total: Number(row.grand_total) || 0,
            });
        }

        const summaryWs = workbook.addWorksheet("Summary");
        summaryWs.addRow(["Organization", data.domain?.name ?? ""]);
        summaryWs.addRow(["Organization slug", data.domain?.name_slug ?? ""]);
        summaryWs.addRow([
            "Period",
            `${data.filters?.start_date ?? ""} to ${data.filters?.end_date ?? ""}`,
        ]);
        summaryWs.addRow(["Location filter", locationFilterLabel.value]);
        summaryWs.addRow([]);
        summaryWs.addRow([
            "Total output VAT",
            Number(data.summary?.total_vat) || 0,
        ]);
        summaryWs.addRow([
            "Gross sales (paid)",
            Number(data.summary?.gross_sales) || 0,
        ]);
        summaryWs.addRow([
            "Number of sales",
            Number(data.summary?.sales_count) || 0,
        ]);
        summaryWs.getColumn(1).width = 22;
        summaryWs.getColumn(2).width = 28;

        const slug = (
            String(data.domain?.name_slug ?? "vat-register").replace(
                /[^a-zA-Z0-9_-]/g,
                "-",
            ) || "vat-register"
        ).slice(0, 80);
        const fn = `vat-register-${slug}-${data.filters?.start_date ?? ""}-${data.filters?.end_date ?? ""}.xlsx`;

        const buffer = await workbook.xlsx.writeBuffer();
        const blob = new Blob([buffer], {
            type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = fn;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    } catch (e) {
        const msg =
            e?.response?.data?.message ||
            e?.message ||
            "Could not export Excel.";
        message.error(
            typeof msg === "string" ? msg : "Could not export Excel.",
        );
    } finally {
        exportingExcel.value = false;
    }
}

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
            class="mb-4 md:mb-6 no-print"
            :title="`VAT report — ${domainName}`"
        />
        <ContentLayout
            title="Output VAT (paid sales)"
            filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
        >
            <template #filters>
                <div class="flex w-full min-w-0 flex-col gap-1 md:w-auto">
                    <span class="text-xs text-gray-600">Date range</span>
                    <a-range-picker
                        v-model:value="dateRangeLocal"
                        format="YYYY-MM-DD"
                        class="w-full min-w-0 md:min-w-[260px]"
                    />
                </div>
                <div class="flex w-full min-w-0 flex-col gap-1 md:w-auto">
                    <span class="text-xs text-gray-600">Location</span>
                    <a-select
                        v-model:value="locationIdLocal"
                        allow-clear
                        placeholder="All locations"
                        class="w-full min-w-0 md:min-w-[200px]"
                        :options="
                            (locations || []).map((l) => ({
                                value: l.id,
                                label: l.name,
                            }))
                        "
                    />
                </div>
                <a-button
                    type="primary"
                    class="w-full md:w-auto"
                    @click="applyFilters"
                >
                    Apply
                </a-button>
                <a-button
                    type="default"
                    class="w-full md:w-auto"
                    :loading="exportingExcel"
                    @click="exportExcel"
                >
                    Export Excel
                </a-button>
                <span v-print="vatPrintOptions" class="block w-full md:w-auto">
                    <a-button
                        type="default"
                        class="flex w-full items-center justify-center gap-2 md:inline-flex md:w-auto"
                    >
                        <template #icon>
                            <IconPrinter :size="20" />
                        </template>
                        Print
                    </a-button>
                </span>
            </template>

            <template #table>
                <div
                    class="vat-report-print-root space-y-6 px-4 pb-8 pt-2 md:px-6 max-w-7xl"
                >
                    <p class="text-sm text-gray-600 no-print">
                        Totals use
                        <strong>paid</strong> sales only, filtered by
                        <strong>transaction date</strong>. This supports
                        reconciliation and filing prep; it is not a substitute
                        for full input VAT unless you track purchases
                        separately.
                    </p>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div
                            class="w-full rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
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
                            class="w-full rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
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
                            class="w-full rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
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
                            v-if="isMdUp"
                            :columns="columns"
                            :data-source="txData"
                            :pagination="false"
                            :row-key="(r) => r.id"
                            size="small"
                            bordered
                            class="bg-white"
                        />

                        <div v-else class="w-full py-2 md:px-0">
                            <div
                                v-if="!txData.length"
                                class="py-12 text-center text-sm text-gray-500"
                            >
                                No transactions found for this period.
                            </div>
                            <div v-else class="flex w-full flex-col gap-3">
                                <div
                                    v-for="row in txData"
                                    :key="row.id"
                                    class="w-full overflow-hidden rounded-none border border-gray-200 bg-white shadow-sm md:rounded-lg"
                                >
                                    <div
                                        class="flex flex-wrap items-start justify-between gap-2 px-4 py-3"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="truncate text-sm font-semibold text-gray-900"
                                            >
                                                {{ row.reference }}
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                {{ row.transaction_date_display }}
                                            </p>
                                        </div>
                                        <span
                                            class="text-base font-semibold text-gray-900"
                                        >
                                            {{ formattedTotal(row.grand_total) }}
                                        </span>
                                    </div>
                                    <div
                                        class="mx-4 mb-3 rounded-lg bg-gray-50 p-3"
                                    >
                                        <div
                                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
                                        >
                                            <span class="text-gray-500"
                                                >Receipt</span
                                            >
                                            <span
                                                class="truncate text-right font-medium text-gray-900"
                                            >
                                                {{ row.reference }}
                                            </span>
                                            <span class="text-gray-500"
                                                >Date</span
                                            >
                                            <span
                                                class="text-right font-medium text-gray-900"
                                            >
                                                {{
                                                    row.transaction_date_display
                                                }}
                                            </span>
                                            <span class="text-gray-500"
                                                >Net</span
                                            >
                                            <span
                                                class="text-right font-medium text-gray-900"
                                            >
                                                {{
                                                    formattedTotal(
                                                        row.taxable_net,
                                                    )
                                                }}
                                            </span>
                                            <span class="text-gray-500"
                                                >VAT</span
                                            >
                                            <span
                                                class="text-right font-medium text-gray-900"
                                            >
                                                {{
                                                    formattedTotal(row.tax_amount)
                                                }}
                                            </span>
                                            <span class="text-gray-500"
                                                >Gross</span
                                            >
                                            <span
                                                class="text-right font-semibold text-gray-900"
                                            >
                                                {{
                                                    formattedTotal(row.grand_total)
                                                }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="txData.length"
                            class="flex w-full flex-col gap-2 rounded-b border border-t-0 border-gray-200 bg-gray-50 px-3 py-2 text-sm md:flex-row md:flex-wrap md:justify-end md:gap-6"
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
