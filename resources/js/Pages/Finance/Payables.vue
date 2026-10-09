<script setup>
import { computed, ref } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { message } from "ant-design-vue";
import { IconPlus, IconEdit, IconTrash, IconTruckDelivery } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useFinanceFilters } from "./composables/useFinanceFilters";
import FinanceNav from "./components/FinanceNav.vue";
import FinancePeriod from "./components/FinancePeriod.vue";
import ExplainButton from "./components/ExplainButton.vue";
import MetricCard from "./components/MetricCard.vue";
import PaymentMethodFields from "./components/PaymentMethodFields.vue";
import { paymentMethodLabel } from "@/Pages/Expenses/paymentMethods";

const props = defineProps({
    filters: { type: Object, required: true },
    payables: { type: Object, required: true },
    bills: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    defaultLocationId: { type: Number, default: null },
    aiEnabled: { type: Boolean, default: false },
    domainName: { type: String, default: "" },
});

const { formattedTotal } = useHelpers();
const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();

const statusOptions = [
    { value: "open", label: "Not fully paid" },
    { value: "overdue", label: "Overdue" },
    { value: "paid", label: "Paid" },
    { value: "all", label: "All bills" },
];
const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));

// Supplier bills cover the whole business: no location filter.
const { filters, filtersConfig, activeFilters, handleClearSelectedFilter, clearAll, load, spinning, periodLabel } = useFinanceFilters({
    routeName: "finance.payables.index",
    serverFilters: () => props.filters,
    showLocation: false,
    extra: [
        { key: "status", label: "Bills", param: "status", options: statusOptions },
        { key: "supplier", label: "Supplier", param: "supplier_id", options: supplierOptions },
    ],
});

const ap = computed(() => props.payables);
const paidChange = computed(() => {
    const now = ap.value.period.paid;
    const before = ap.value.previous_period.paid;
    return { amount: now - before, pct: before ? Math.round(((now - before) / before) * 1000) / 10 : null };
});

const statusTag = {
    unpaid: { color: "default", label: "Unpaid" },
    partial: { color: "blue", label: "Partly paid" },
    overdue: { color: "red", label: "Overdue" },
    paid: { color: "green", label: "Paid" },
};
const fmtDate = (d) => (d ? dayjs(d).format("MMM D, YYYY") : "—");

/*** === BILLS === ***/
const billModal = ref(false);
const editingBill = ref(null);
const billForm = useForm({
    supplier_id: null,
    bill_number: "",
    bill_date: dayjs().format("YYYY-MM-DD"),
    due_date: null,
    bill_type: "inventory",
    expense_category_id: null,
    amount: null,
    location_id: props.defaultLocationId,
    notes: "",
});

/** Due date the supplier's usual terms give, shown until one is picked. */
const suggestedDue = computed(() => {
    const supplier = props.suppliers.find((s) => s.id === billForm.supplier_id);
    if (!supplier || !billForm.bill_date) return null;
    return dayjs(billForm.bill_date).add(supplier.payment_terms_days, "day");
});

function openBill(row = null) {
    editingBill.value = row;
    billForm.clearErrors();
    if (row) {
        Object.assign(billForm, {
            supplier_id: row.supplier_id,
            bill_number: row.bill_number ?? "",
            bill_date: row.bill_date,
            due_date: row.due_date,
            bill_type: row.bill_type,
            expense_category_id: row.expense_category_id,
            amount: Number(row.amount),
            location_id: row.location_id,
            notes: row.notes ?? "",
        });
    } else {
        billForm.reset();
        billForm.bill_date = dayjs().format("YYYY-MM-DD");
    }
    billModal.value = true;
}

function saveBill() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            billModal.value = false;
            message.success(editingBill.value ? "Bill updated" : "Bill recorded");
        },
    };
    if (editingBill.value) billForm.put(getRoute("finance.bills.update", { bill: editingBill.value.id }), options);
    else billForm.post(getRoute("finance.bills.store"), options);
}

const deleter = useForm({});
function deleteBill(row) {
    deleter.delete(getRoute("finance.bills.destroy", { bill: row.id }), {
        preserveScroll: true,
        onSuccess: () => message.success("Bill deleted"),
        onError: (e) => message.error(Object.values(e)[0] ?? "Could not delete the bill"),
    });
}

/*** === PAYMENTS === ***/
const paymentModal = ref(false);
const payingBill = ref(null);
const paymentForm = useForm({
    payment_date: dayjs().format("YYYY-MM-DD"),
    amount: null,
    payment_method: "cash_register",
    location_id: props.defaultLocationId,
    reference_no: "",
    notes: "",
});

function openPayment(bill) {
    payingBill.value = bill;
    paymentForm.reset();
    paymentForm.clearErrors();
    paymentForm.payment_date = dayjs().format("YYYY-MM-DD");
    paymentForm.amount = bill.remaining;
    paymentModal.value = true;
}

function savePayment() {
    paymentForm.post(getRoute("finance.bill-payments.store", { bill: payingBill.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            paymentModal.value = false;
            message.success("Payment recorded");
        },
    });
}

function deletePayment(payment) {
    deleter.delete(getRoute("finance.bill-payments.destroy", { payment: payment.id }), {
        preserveScroll: true,
        onSuccess: () => message.success("Payment deleted"),
        onError: (e) => message.error(Object.values(e)[0] ?? "Could not delete the payment"),
    });
}

/*** === SUPPLIERS === ***/
const supplierModal = ref(false);
const editingSupplier = ref(null);
const supplierForm = useForm({ name: "", contact_person: "", phone: "", email: "", payment_terms_days: 30, notes: "", is_active: true });

function openSupplier(row = null) {
    editingSupplier.value = row;
    supplierForm.clearErrors();
    if (row) {
        Object.assign(supplierForm, {
            name: row.name,
            contact_person: row.contact_person ?? "",
            phone: row.phone ?? "",
            email: row.email ?? "",
            payment_terms_days: row.payment_terms_days,
            notes: row.notes ?? "",
            is_active: row.is_active,
        });
    }
    else supplierForm.reset();
    supplierModal.value = true;
}

function saveSupplier() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            supplierModal.value = false;
            message.success(editingSupplier.value ? "Supplier updated" : "Supplier added");
            // A supplier added while recording a bill is picked for it.
            if (!editingSupplier.value && billModal.value) {
                const added = props.suppliers.find((s) => s.name === supplierForm.name);
                if (added) billForm.supplier_id = added.id;
            }
        },
    };
    if (editingSupplier.value) supplierForm.put(getRoute("finance.suppliers.update", { supplier: editingSupplier.value.id }), options);
    else supplierForm.post(getRoute("finance.suppliers.store"), options);
}

const suppliersDrawer = ref(false);

/*** === TABLES === ***/
const pagination = computed(() => ({
    total: props.bills.total,
    current: props.bills.current_page,
    pageSize: props.bills.per_page,
    showSizeChanger: false,
}));

const billColumns = [
    { title: "Supplier", key: "supplier" },
    { title: "Bill date", dataIndex: "bill_date", key: "bill_date", width: 120, customRender: ({ text }) => fmtDate(text) },
    { title: "Due", dataIndex: "due_date", key: "due_date", width: 120, customRender: ({ text }) => fmtDate(text) },
    { title: "Amount", dataIndex: "amount", key: "amount", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "Left to pay", dataIndex: "remaining", key: "remaining", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "Status", key: "status", width: 110 },
    { title: "", key: "actions", width: 150, align: "right" },
];

const smallBillColumns = [
    { title: "Supplier", dataIndex: "supplier", key: "supplier", ellipsis: true },
    { title: "Due", dataIndex: "due_date", key: "due_date", customRender: ({ record }) => (record.days_overdue ? `${record.days_overdue} days late` : fmtDate(record.due_date)) },
    { title: "Left to pay", dataIndex: "remaining", key: "remaining", align: "right", customRender: ({ text }) => formattedTotal(text) },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Supplier bills" />
        <ContentHeader class="mb-4 md:mb-6" title="Supplier bills" />
        <ContentLayout title="Who do I owe?" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="load()" />
                <ExplainButton topic="payables" :filters="props.filters" :ai-enabled="aiEnabled"
                    label="Explain what I owe" title="Your supplier bills in plain words" size="middle" />
                <a-button
                    v-if="hasPermission('finance.bills.store')"
                    type="primary"
                    class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
                    data-testid="add-bill"
                    @click="openBill()"
                >
                    <template #icon><IconPlus /></template>
                    Record bill
                </a-button>
                <a-button class="w-full md:w-auto" data-testid="open-suppliers" @click="suppliersDrawer = true">
                    <span class="inline-flex items-center gap-1"><IconTruckDelivery :size="16" /> Suppliers</span>
                </a-button>
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
            </template>

            <template #activeFilters>
                <ActiveFilters :filters="activeFilters" @remove-filter="handleClearSelectedFilter" @clear-all="clearAll" />
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.payables.index" :filters="props.filters" />
                    <FinancePeriod :label="periodLabel" note="Balances are as of today; the date filter sets “paid this period”" />

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4" data-testid="payables-summary">
                        <MetricCard label="You owe suppliers" :value="ap.outstanding" :hint="`${ap.open_bills} open bill(s), ${ap.suppliers_owed} supplier(s)`" />
                        <MetricCard label="Overdue" :value="ap.overdue" hint="Past the due date" />
                        <MetricCard label="Due in the next 7 days" :value="ap.due_within_7_days" hint="Set this money aside" />
                        <MetricCard label="Paid this period" :value="ap.period.paid" :change="paidChange"
                            :hint="`${formattedTotal(ap.period.billed)} newly billed`" />
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <section class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900">Coming up (30 days)</h2>
                            <a-table :columns="smallBillColumns" :data-source="ap.upcoming" :pagination="false" row-key="id" size="small" bordered class="bg-white" />
                        </section>
                        <section class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900">Overdue bills</h2>
                            <a-table :columns="smallBillColumns" :data-source="ap.overdue_bills" :pagination="false" row-key="id" size="small" bordered class="bg-white" />
                        </section>
                        <section class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900">Largest balances</h2>
                            <div class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-sm">
                                <div v-if="!ap.largest.length" class="p-3 text-gray-500">Nothing owed.</div>
                                <div v-for="row in ap.largest" :key="row.supplier_id" class="flex justify-between gap-2 px-3 py-2">
                                    <span class="text-gray-700">{{ row.name }}</span>
                                    <span class="text-right">
                                        <span class="font-medium text-gray-900">{{ formattedTotal(row.balance) }}</span>
                                        <span v-if="row.overdue > 0" class="block text-xs text-red-600">{{ formattedTotal(row.overdue) }} overdue</span>
                                    </span>
                                </div>
                            </div>
                        </section>
                    </div>

                    <section class="space-y-3">
                        <h2 class="text-base font-semibold text-gray-900">Bills</h2>
                        <a-table
                            :columns="billColumns"
                            :data-source="bills.data"
                            :pagination="pagination"
                            row-key="id"
                            size="small"
                            bordered
                            class="bg-white"
                            :scroll="{ x: 900 }"
                            data-testid="bill-table"
                            @change="(p) => load({ page: p.current })"
                        >
                            <template #bodyCell="{ column, record }">
                                <template v-if="column.key === 'supplier'">
                                    <span class="font-medium text-gray-900">{{ record.supplier?.name }}</span>
                                    <div class="text-xs text-gray-500">
                                        {{ record.bill_number ? `Bill ${record.bill_number} · ` : "" }}{{ record.bill_type === "inventory" ? "Stock" : record.category?.name }}
                                    </div>
                                </template>
                                <template v-else-if="column.key === 'status'">
                                    <a-tag :color="statusTag[record.status].color">{{ statusTag[record.status].label }}</a-tag>
                                </template>
                                <template v-else-if="column.key === 'actions'">
                                    <div class="flex justify-end gap-1">
                                        <a-button v-if="record.remaining > 0 && hasPermission('finance.bill-payments.store')" size="small"
                                            data-testid="pay-bill" @click="openPayment(record)">Pay</a-button>
                                        <a-button v-if="hasPermission('finance.bills.update')" size="small" type="text" @click="openBill(record)">
                                            <IconEdit :size="16" />
                                        </a-button>
                                        <a-popconfirm v-if="hasPermission('finance.bills.destroy')" title="Delete this bill?" ok-text="Delete"
                                            ok-type="danger" @confirm="deleteBill(record)">
                                            <a-button size="small" type="text" danger><IconTrash :size="16" /></a-button>
                                        </a-popconfirm>
                                    </div>
                                </template>
                            </template>
                            <template #expandedRowRender="{ record }">
                                <div v-if="!record.payments.length" class="text-sm text-gray-500">No payments yet.</div>
                                <div v-for="p in record.payments" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-1 text-sm">
                                    <span class="text-gray-700">
                                        {{ fmtDate(p.payment_date) }} · {{ formattedTotal(p.amount) }} ·
                                        {{ paymentMethodLabel(p.payment_method) }}{{ p.location ? ` (${p.location.name})` : "" }}
                                        <span v-if="p.reference_no" class="text-gray-400"> · {{ p.reference_no }}</span>
                                    </span>
                                    <a-popconfirm v-if="hasPermission('finance.bill-payments.destroy')" title="Delete this payment?" ok-text="Delete"
                                        ok-type="danger" @confirm="deletePayment(p)">
                                        <a-button size="small" type="link" danger>Delete</a-button>
                                    </a-popconfirm>
                                </div>
                                <p v-if="record.notes" class="mt-1 text-xs text-gray-500">{{ record.notes }}</p>
                            </template>
                        </a-table>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-base font-semibold text-gray-900">Recently paid</h2>
                        <div class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-sm">
                            <div v-if="!ap.recently_paid.length" class="p-3 text-gray-500">No supplier payments yet.</div>
                            <div v-for="p in ap.recently_paid" :key="p.id" class="flex justify-between gap-2 px-3 py-2">
                                <span class="text-gray-700">{{ p.supplier }}<span v-if="p.bill_number" class="text-gray-400"> · {{ p.bill_number }}</span></span>
                                <span class="text-gray-900">{{ formattedTotal(p.amount) }} <span class="text-gray-400">· {{ fmtDate(p.payment_date) }}</span></span>
                            </div>
                        </div>
                    </section>
                </div>
            </template>
        </ContentLayout>

        <!-- Record / edit bill -->
        <a-modal v-model:visible="billModal" :title="editingBill ? 'Edit bill' : 'Record supplier bill'"
            :confirm-loading="billForm.processing" ok-text="Save" @ok="saveBill">
            <a-form layout="vertical" data-testid="bill-form">
                <a-form-item label="Supplier" :validate-status="billForm.errors.supplier_id ? 'error' : ''" :help="billForm.errors.supplier_id" required>
                    <div class="flex gap-2">
                        <a-select v-model:value="billForm.supplier_id" show-search option-filter-prop="label" placeholder="Pick a supplier"
                            class="flex-1" :options="suppliers.filter((s) => s.is_active || s.id === billForm.supplier_id).map((s) => ({ value: s.id, label: s.name }))"
                            data-testid="bill-supplier" />
                        <a-button v-if="hasPermission('finance.suppliers.store')" @click="openSupplier()">New</a-button>
                    </div>
                </a-form-item>
                <a-form-item label="What is it for?" required>
                    <a-radio-group v-model:value="billForm.bill_type" button-style="solid" class="flex w-full">
                        <a-radio-button value="inventory" class="flex-1 text-center">Stock to sell</a-radio-button>
                        <a-radio-button value="expense" class="flex-1 text-center">An expense</a-radio-button>
                    </a-radio-group>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ billForm.bill_type === "inventory"
                            ? "Stock counts as cost of goods sold when it is sold, not as an expense now."
                            : "Counts as an expense on the bill date, under its category." }}
                    </p>
                </a-form-item>
                <a-form-item v-if="billForm.bill_type === 'expense'" label="Expense category"
                    :validate-status="billForm.errors.expense_category_id ? 'error' : ''" :help="billForm.errors.expense_category_id" required>
                    <a-select v-model:value="billForm.expense_category_id" :options="categories.map((c) => ({ value: c.id, label: c.name }))" />
                </a-form-item>
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Amount" :validate-status="billForm.errors.amount ? 'error' : ''" :help="billForm.errors.amount" required>
                        <a-input-number v-model:value="billForm.amount" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="bill-amount" />
                    </a-form-item>
                    <a-form-item label="Bill / invoice no.">
                        <a-input v-model:value="billForm.bill_number" />
                    </a-form-item>
                    <a-form-item label="Bill date" :validate-status="billForm.errors.bill_date ? 'error' : ''" :help="billForm.errors.bill_date" required>
                        <a-date-picker :value="billForm.bill_date ? dayjs(billForm.bill_date) : null" class="w-full"
                            @change="(d) => (billForm.bill_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                    <a-form-item label="Due date" :validate-status="billForm.errors.due_date ? 'error' : ''"
                        :help="billForm.errors.due_date || (!billForm.due_date && suggestedDue ? `Leave empty for ${suggestedDue.format('MMM D, YYYY')} (supplier terms)` : '')">
                        <a-date-picker :value="billForm.due_date ? dayjs(billForm.due_date) : null" class="w-full"
                            @change="(d) => (billForm.due_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <a-form-item v-if="locations.length > 1" label="Store (optional)">
                    <a-select v-model:value="billForm.location_id" allow-clear placeholder="Whole business"
                        :options="locations.map((l) => ({ value: l.id, label: l.name }))" />
                </a-form-item>
                <a-form-item label="Notes">
                    <a-textarea v-model:value="billForm.notes" :rows="2" />
                </a-form-item>
            </a-form>
        </a-modal>

        <!-- Pay a bill -->
        <a-modal v-model:visible="paymentModal" title="Record payment" :confirm-loading="paymentForm.processing" ok-text="Save payment" @ok="savePayment">
            <p v-if="payingBill" class="mb-4 text-sm text-gray-600">
                {{ payingBill.supplier?.name }}<span v-if="payingBill.bill_number"> · bill {{ payingBill.bill_number }}</span> —
                <strong>{{ formattedTotal(payingBill.remaining) }}</strong> left to pay.
            </p>
            <a-form layout="vertical" data-testid="payment-form">
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Amount" :validate-status="paymentForm.errors.amount ? 'error' : ''" :help="paymentForm.errors.amount" required>
                        <a-input-number v-model:value="paymentForm.amount" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="payment-amount" />
                    </a-form-item>
                    <a-form-item label="Date" :validate-status="paymentForm.errors.payment_date ? 'error' : ''" :help="paymentForm.errors.payment_date" required>
                        <a-date-picker :value="paymentForm.payment_date ? dayjs(paymentForm.payment_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')"
                            @change="(d) => (paymentForm.payment_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <PaymentMethodFields :form="paymentForm" :methods="paymentMethods" :locations="locations" />
                <a-form-item label="Reference (optional)">
                    <a-input v-model:value="paymentForm.reference_no" placeholder="Check no., transfer reference…" />
                </a-form-item>
            </a-form>
        </a-modal>

        <!-- Suppliers list -->
        <a-drawer v-model:visible="suppliersDrawer" title="Suppliers" placement="right" :width="420">
            <a-button v-if="hasPermission('finance.suppliers.store')" type="primary" block class="mb-4" @click="openSupplier()">
                <template #icon><IconPlus /></template>
                Add supplier
            </a-button>
            <div v-if="!suppliers.length" class="text-sm text-gray-500">No suppliers yet.</div>
            <div class="divide-y divide-gray-100">
                <div v-for="s in suppliers" :key="s.id" class="flex items-start justify-between gap-2 py-3 text-sm">
                    <div>
                        <p class="font-medium" :class="s.is_active ? 'text-gray-900' : 'text-gray-400'">{{ s.name }}</p>
                        <p class="text-xs text-gray-500">
                            {{ [s.contact_person, s.phone].filter(Boolean).join(" · ") || "No contact details" }} · {{ s.payment_terms_days }}-day terms
                        </p>
                        <p v-if="s.balance > 0" class="text-xs text-gray-700">Owed: {{ formattedTotal(s.balance) }}</p>
                    </div>
                    <a-button v-if="hasPermission('finance.suppliers.update')" size="small" type="text" @click="openSupplier(s)">
                        <IconEdit :size="16" />
                    </a-button>
                </div>
            </div>
        </a-drawer>

        <!-- Add / edit supplier -->
        <a-modal v-model:visible="supplierModal" :title="editingSupplier ? 'Edit supplier' : 'Add supplier'"
            :confirm-loading="supplierForm.processing" ok-text="Save" @ok="saveSupplier">
            <a-form layout="vertical" data-testid="supplier-form">
                <a-form-item label="Name" :validate-status="supplierForm.errors.name ? 'error' : ''" :help="supplierForm.errors.name" required>
                    <a-input v-model:value="supplierForm.name" data-testid="supplier-name" />
                </a-form-item>
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Contact person"><a-input v-model:value="supplierForm.contact_person" /></a-form-item>
                    <a-form-item label="Phone"><a-input v-model:value="supplierForm.phone" /></a-form-item>
                    <a-form-item label="Email" :validate-status="supplierForm.errors.email ? 'error' : ''" :help="supplierForm.errors.email">
                        <a-input v-model:value="supplierForm.email" />
                    </a-form-item>
                    <a-form-item label="Pays bills within (days)" :validate-status="supplierForm.errors.payment_terms_days ? 'error' : ''"
                        :help="supplierForm.errors.payment_terms_days" required>
                        <a-input-number v-model:value="supplierForm.payment_terms_days" :min="0" :max="365" class="w-full" />
                    </a-form-item>
                </div>
                <a-form-item label="Notes"><a-textarea v-model:value="supplierForm.notes" :rows="2" /></a-form-item>
                <a-checkbox v-if="editingSupplier" v-model:checked="supplierForm.is_active">Active</a-checkbox>
            </a-form>
        </a-modal>
    </AuthenticatedLayout>
</template>
