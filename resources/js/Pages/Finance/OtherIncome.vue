<script setup>
import { ref } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { message } from "ant-design-vue";
import { IconPlus, IconEdit, IconTrash } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { paymentMethodLabel } from "@/Pages/Expenses/paymentMethods";
import { useFinanceFilters } from "./composables/useFinanceFilters";
import FinanceNav from "./components/FinanceNav.vue";
import FinancePeriod from "./components/FinancePeriod.vue";
import MetricCard from "./components/MetricCard.vue";
import PaymentMethodFields from "./components/PaymentMethodFields.vue";

const props = defineProps({
    filters: { type: Object, required: true },
    items: { type: Object, required: true },
    total: { type: Number, default: 0 },
    locations: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    defaultLocationId: { type: Number, default: null },
    domainName: { type: String, default: "" },
});

const { formattedTotal } = useHelpers();
const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();
const { filters, filtersConfig, activeFilters, handleClearSelectedFilter, clearAll, load, spinning, periodLabel } = useFinanceFilters({
    routeName: "finance.other-income.index",
    serverFilters: () => props.filters,
    locations: props.locations,
});

const modal = ref(false);
const editing = ref(null);
const form = useForm({
    income_date: dayjs().format("YYYY-MM-DD"),
    amount: null,
    description: "",
    payment_method: "cash_register",
    location_id: props.defaultLocationId,
    reference_no: "",
});

function open(row = null) {
    editing.value = row;
    form.clearErrors();
    if (row) {
        Object.assign(form, {
            income_date: row.income_date,
            amount: Number(row.amount),
            description: row.description,
            payment_method: row.payment_method,
            location_id: row.location_id,
            reference_no: row.reference_no ?? "",
        });
    } else {
        form.reset();
        form.income_date = dayjs().format("YYYY-MM-DD");
    }
    modal.value = true;
}

function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            modal.value = false;
            message.success(editing.value ? "Income updated" : "Income recorded");
        },
    };
    if (editing.value) form.put(getRoute("finance.other-income.update", { otherIncome: editing.value.id }), options);
    else form.post(getRoute("finance.other-income.store"), options);
}

const deleter = useForm({});
function remove(row) {
    deleter.delete(getRoute("finance.other-income.destroy", { otherIncome: row.id }), {
        preserveScroll: true,
        onSuccess: () => message.success("Income deleted"),
        onError: (e) => message.error(Object.values(e)[0] ?? "Could not delete"),
    });
}

const columns = [
    { title: "Date", dataIndex: "income_date", key: "date", width: 130, customRender: ({ text }) => dayjs(text).format("MMM D, YYYY") },
    { title: "Description", dataIndex: "description", key: "description" },
    { title: "Received by", key: "method" },
    { title: "Amount", dataIndex: "amount", key: "amount", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "", key: "actions", width: 90, align: "right" },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Other income" />
        <ContentHeader class="mb-4 md:mb-6" title="Other income" />
        <ContentLayout title="Money earned outside of sales" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="load()" />
                <a-button
                    v-if="hasPermission('finance.other-income.store')"
                    type="primary"
                    class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
                    data-testid="add-income"
                    @click="open()"
                >
                    <template #icon><IconPlus /></template>
                    Record income
                </a-button>
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <template #activeFilters>
                <ActiveFilters :filters="activeFilters" @remove-filter="handleClearSelectedFilter" @clear-all="clearAll" />
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.other-income.index" :filters="props.filters" />
                    <FinancePeriod :label="periodLabel" />

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <MetricCard label="Other income this period" :value="total"
                            hint="Renting out space, supplier rebates, interest…; shown under other income on the income statement" />
                    </div>

                    <a-table
                        :columns="columns"
                        :data-source="items.data"
                        :pagination="{ total: items.total, current: items.current_page, pageSize: items.per_page, showSizeChanger: false }"
                        row-key="id"
                        size="small"
                        bordered
                        class="bg-white"
                        :scroll="{ x: 640 }"
                        data-testid="income-table"
                        @change="(p) => load({ page: p.current })"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'method'">
                                {{ paymentMethodLabel(record.payment_method) }}<span v-if="record.location" class="text-gray-400"> · {{ record.location.name }}</span>
                                <div v-if="record.reference_no" class="text-xs text-gray-500">{{ record.reference_no }}</div>
                            </template>
                            <template v-else-if="column.key === 'actions'">
                                <div class="flex justify-end gap-1">
                                    <a-button v-if="hasPermission('finance.other-income.update')" size="small" type="text" @click="open(record)">
                                        <IconEdit :size="16" />
                                    </a-button>
                                    <a-popconfirm v-if="hasPermission('finance.other-income.destroy')" title="Delete this income?"
                                        ok-text="Delete" ok-type="danger" @confirm="remove(record)">
                                        <a-button size="small" type="text" danger><IconTrash :size="16" /></a-button>
                                    </a-popconfirm>
                                </div>
                            </template>
                        </template>
                    </a-table>
                </div>
            </template>
        </ContentLayout>

        <a-modal v-model:visible="modal" :title="editing ? 'Edit other income' : 'Record other income'"
            :confirm-loading="form.processing" ok-text="Save" @ok="save">
            <a-form layout="vertical" data-testid="income-form">
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Date" :validate-status="form.errors.income_date ? 'error' : ''" :help="form.errors.income_date" required>
                        <a-date-picker :value="form.income_date ? dayjs(form.income_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')"
                            @change="(d) => (form.income_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                    <a-form-item label="Amount" :validate-status="form.errors.amount ? 'error' : ''" :help="form.errors.amount" required>
                        <a-input-number v-model:value="form.amount" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="income-amount" />
                    </a-form-item>
                </div>
                <a-form-item label="What is it?" :validate-status="form.errors.description ? 'error' : ''" :help="form.errors.description" required>
                    <a-input v-model:value="form.description" placeholder="e.g. Rent from stall space, supplier rebate" data-testid="income-description" />
                </a-form-item>
                <PaymentMethodFields :form="form" direction="in" :methods="paymentMethods" :locations="locations" />
                <a-form-item label="Reference (optional)">
                    <a-input v-model:value="form.reference_no" />
                </a-form-item>
            </a-form>
        </a-modal>
    </AuthenticatedLayout>
</template>
