<script setup>
import { computed, ref } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import axios from "axios";
import { message } from "ant-design-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

const props = defineProps({
    locations: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    maxRows: { type: Number, default: 2000 },
    domainName: { type: String, default: "" },
});

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const locationId = ref(props.locations.length === 1 ? props.locations[0].id : null);
const fileList = ref([]);
const deductStock = ref(false);
const checking = ref(false);
const importing = ref(false);
const preview = ref(null);
const done = ref(null);

const locationOptions = computed(() => props.locations.map((l) => ({ value: l.id, label: l.name })));
const canCheck = computed(() => fileList.value.length > 0 && !!locationId.value && !checking.value);

const errorMessage = (e) =>
    e?.response?.data?.message ||
    Object.values(e?.response?.data?.errors || {})[0]?.[0] ||
    "Something went wrong. Please try again.";

const reset = () => {
    fileList.value = [];
    preview.value = null;
    done.value = null;
    deductStock.value = false;
};

const beforeUpload = (file) => {
    fileList.value = [file];
    preview.value = null;
    return false; // keep the file here; it is sent when "Check file" is pressed
};

const checkFile = async () => {
    checking.value = true;
    preview.value = null;
    try {
        const body = new FormData();
        body.append("file", fileList.value[0].originFileObj || fileList.value[0]);
        body.append("location_id", locationId.value);
        const { data } = await axios.post(getRoute("sales-import.preview"), body);
        preview.value = data;
    } catch (e) {
        message.error(errorMessage(e));
    } finally {
        checking.value = false;
    }
};

const confirmImport = async () => {
    importing.value = true;
    try {
        const { data } = await axios.post(getRoute("sales-import.store"), {
            token: preview.value.token,
            deduct_stock: deductStock.value,
        });
        done.value = data;
        preview.value = null;
        fileList.value = [];
        message.success(`${data.sales_count} sales imported.`);
    } catch (e) {
        message.error(errorMessage(e));
    } finally {
        importing.value = false;
    }
};

const errorColumns = [
    { title: "Row", dataIndex: "line", width: 80 },
    { title: "Problem", dataIndex: "message" },
];
const salesColumns = [
    { title: "Receipt", dataIndex: "receipt_no" },
    { title: "Date", dataIndex: "date" },
    { title: "Payment", dataIndex: "payment_method" },
    { title: "Items", dataIndex: "items", align: "right" },
    { title: "Total", dataIndex: "total", align: "right" },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Import Sales" />
        <ContentHeader class="mb-4 md:mb-6" title="Import Past Sales" />

        <div class="space-y-6 px-4 pb-8 md:px-6" data-testid="sales-import-page">
            <a-alert
                type="info"
                show-icon
                message="Use this to add sales that already happened, such as from a notebook or an old system."
                :description="`Each row is one item. Rows with the same receipt_no become one sale. Up to ${maxRows} rows per file. Imported sales do not touch your cash drawer, and do not change stock unless you tick the box below.`"
            />

            <!-- Done -->
            <a-result
                v-if="done"
                status="success"
                :title="`${done.sales_count} sales imported`"
                sub-title="They now show on their real dates in Sales History and your reports."
                data-testid="sales-import-done"
            >
                <template #extra>
                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <Link :href="getRoute('sales-history.index')"><a-button type="primary">Open Sales History</a-button></Link>
                        <a-button @click="reset">Import another file</a-button>
                    </div>
                </template>
            </a-result>

            <template v-else>
                <!-- Step 1 -->
                <a-card title="1. Get the template">
                    <p class="mb-4 text-sm text-gray-600">
                        Download the CSV, fill in one row per item sold, then save it as CSV. Use the product's barcode (best) or SKU.
                        Leave <b>unit_price</b> empty to use the product's current price. Payment method: cash, card, e-wallet or bank.
                    </p>
                    <a :href="getRoute('sales-import.template')" data-testid="sales-import-template">
                        <a-button>Download template (CSV)</a-button>
                    </a>
                </a-card>

                <!-- Step 2 -->
                <a-card title="2. Upload your file">
                    <div class="space-y-5">
                        <div>
                            <div class="mb-2 text-sm font-medium">Which store made these sales?</div>
                            <a-select
                                v-model:value="locationId"
                                :options="locationOptions"
                                placeholder="Select a store"
                                style="width: 280px; max-width: 100%"
                                data-testid="sales-import-location"
                            />
                        </div>
                        <div>
                            <div class="mb-2 text-sm font-medium">CSV file</div>
                            <a-upload
                                :file-list="fileList"
                                :before-upload="beforeUpload"
                                :max-count="1"
                                accept=".csv,text/csv"
                                @remove="reset"
                            >
                                <a-button data-testid="sales-import-choose">Choose CSV file</a-button>
                            </a-upload>
                        </div>
                        <div class="border-t border-gray-100 pt-4">
                            <a-button type="primary" :disabled="!canCheck" :loading="checking" data-testid="sales-import-check" @click="checkFile">
                                Check file
                            </a-button>
                        </div>
                    </div>
                </a-card>

                <!-- Step 3 -->
                <a-card v-if="preview" title="3. Review and confirm" data-testid="sales-import-preview">
                    <div class="mb-4 flex flex-wrap gap-6 text-sm">
                        <div><div class="text-gray-500">Rows read</div><div class="text-lg font-semibold">{{ preview.rows_count }}</div></div>
                        <div><div class="text-gray-500">Sales ready</div><div class="text-lg font-semibold text-green-600" data-testid="sales-import-ready">{{ preview.sales_count }}</div></div>
                        <div><div class="text-gray-500">Rows with problems</div><div class="text-lg font-semibold" :class="preview.errors_count ? 'text-red-600' : ''">{{ preview.errors_count }}</div></div>
                        <div><div class="text-gray-500">Total</div><div class="text-lg font-semibold">{{ formattedTotal(preview.total) }}</div></div>
                    </div>

                    <a-alert
                        v-if="preview.errors_count"
                        type="warning"
                        show-icon
                        class="mb-4"
                        message="Receipts with a problem are skipped completely. Fix them in your file and upload again, or import the good ones now."
                    />
                    <a-table
                        v-if="preview.errors_count"
                        :columns="errorColumns"
                        :data-source="preview.errors"
                        :pagination="{ pageSize: 10, hideOnSinglePage: true }"
                        row-key="line"
                        size="small"
                        class="mb-4"
                        data-testid="sales-import-errors"
                    />

                    <a-table
                        v-if="preview.sales_count"
                        :columns="salesColumns"
                        :data-source="preview.sales"
                        :pagination="{ pageSize: 10, hideOnSinglePage: true }"
                        row-key="receipt_no"
                        size="small"
                        class="mb-4"
                    >
                        <template #bodyCell="{ column, text }">
                            <template v-if="column.dataIndex === 'total'">{{ formattedTotal(text) }}</template>
                        </template>
                    </a-table>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <a-checkbox v-model:checked="deductStock" data-testid="sales-import-deduct">
                            Also take these items out of stock
                        </a-checkbox>
                        <p class="mb-0 mt-1 pl-6 text-xs text-gray-500">
                            Only tick this if your current stock count does not already include these old sales.
                        </p>
                    </div>

                    <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                        <a-button
                            type="primary"
                            :disabled="!preview.sales_count"
                            :loading="importing"
                            data-testid="sales-import-confirm"
                            @click="confirmImport"
                        >
                            Import {{ preview.sales_count }} sales
                        </a-button>
                        <a-button :disabled="importing" @click="reset">Cancel</a-button>
                    </div>
                </a-card>

                <!-- Past imports -->
                <a-card v-if="history.length" title="Recent imports">
                    <a-table
                        :columns="[
                            { title: 'File', dataIndex: 'file_name' },
                            { title: 'Sales', dataIndex: 'sales_count', align: 'right' },
                            { title: 'Store', dataIndex: 'location' },
                            { title: 'By', dataIndex: 'user' },
                            { title: 'When', dataIndex: 'created_at' },
                        ]"
                        :data-source="history"
                        :pagination="false"
                        row-key="id"
                        size="small"
                    />
                </a-card>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
