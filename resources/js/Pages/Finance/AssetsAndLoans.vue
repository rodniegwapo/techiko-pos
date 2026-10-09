<script setup>
import { ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { message } from "ant-design-vue";
import { IconPlus, IconEdit, IconTrash } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { paymentMethodLabel } from "@/Pages/Expenses/paymentMethods";
import FinanceNav from "./components/FinanceNav.vue";
import MetricCard from "./components/MetricCard.vue";
import PaymentMethodFields from "./components/PaymentMethodFields.vue";

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    loans: { type: Array, default: () => [] },
    assets: { type: Array, default: () => [] },
    investments: { type: Array, default: () => [] },
    otherLiabilities: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    liabilityCategories: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    tab: { type: String, default: "accounts" },
    locations: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    assetCategories: { type: Array, default: () => [] },
    defaultLocationId: { type: Number, default: null },
    domainName: { type: String, default: "" },
});

const { formattedTotal } = useHelpers();
const { getRoute } = useDomainRoutes();
const { spinning } = useGlobalVariables();
const { hasPermission } = usePermissionsV2();

const activeTab = ref(props.tab);
const fmtDate = (d) => (d ? dayjs(d).format("MMM D, YYYY") : "—");
const today = () => dayjs().format("YYYY-MM-DD");
const typeLabel = { bank: "Bank", ewallet: "E-wallet", other: "Other" };
const categoryLabel = { equipment: "Equipment", furniture: "Furniture & fixtures", vehicle: "Vehicle", building: "Building / improvements", other: "Other" };

function reload() {
    router.reload({ onStart: () => (spinning.value = true), onFinish: () => (spinning.value = false) });
}

/** Saves a form (create or update) and closes its modal. */
function submit(form, { create, update, id, idKey, done, label }) {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            done();
            message.success(`${label} saved`);
        },
    };
    if (id) form.put(getRoute(update, { [idKey]: id }), options);
    else form.post(getRoute(create), options);
}

const deleter = useForm({});
function remove(routeName, params, label) {
    deleter.delete(getRoute(routeName, params), {
        preserveScroll: true,
        onSuccess: () => message.success(`${label} deleted`),
        onError: (e) => message.error(Object.values(e)[0] ?? "Could not delete"),
    });
}

/*** === ACCOUNTS === ***/
const accountModal = ref(false);
const editingAccount = ref(null);
const accountForm = useForm({ name: "", type: "bank", account_number: "", payment_card_type_id: null, is_active: true, balance: null, as_of_date: today() });
function openAccount(row = null) {
    editingAccount.value = row;
    accountForm.clearErrors();
    accountForm.reset();
    if (row) {
        Object.assign(accountForm, {
            name: row.name, type: row.type, account_number: row.account_number ?? "",
            payment_card_type_id: row.payment_card_type_id, is_active: row.is_active,
        });
    } else accountForm.as_of_date = today();
    accountModal.value = true;
}

const balanceModal = ref(false);
const balanceAccount = ref(null);
const balanceForm = useForm({ as_of_date: today(), balance: null, note: "" });
function openBalance(account) {
    balanceAccount.value = account;
    balanceForm.reset();
    balanceForm.clearErrors();
    balanceForm.as_of_date = today();
    balanceForm.balance = account.balance !== null ? Number(account.balance) : null;
    balanceModal.value = true;
}
function saveBalance() {
    balanceForm.post(getRoute("finance.account-balances.store", { account: balanceAccount.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            balanceModal.value = false;
            message.success("Balance recorded");
        },
    });
}

/*** === LOANS === ***/
const loanModal = ref(false);
const editingLoan = ref(null);
const loanForm = useForm({
    lender: "", received_date: today(), principal: null, interest_rate: null, due_date: null,
    payment_method: "bank", location_id: props.defaultLocationId, notes: "",
});
function openLoan(row = null) {
    editingLoan.value = row;
    loanForm.clearErrors();
    loanForm.reset();
    if (row) {
        Object.assign(loanForm, {
            lender: row.lender, received_date: row.received_date, principal: Number(row.principal),
            interest_rate: row.interest_rate !== null ? Number(row.interest_rate) : null, due_date: row.due_date,
            payment_method: row.payment_method, location_id: row.location_id, notes: row.notes ?? "",
        });
    } else loanForm.received_date = today();
    loanModal.value = true;
}

const repayModal = ref(false);
const repayLoan = ref(null);
const repayForm = useForm({ payment_date: today(), principal: null, interest: null, payment_method: "bank", location_id: props.defaultLocationId, reference_no: "" });
function openRepay(loan) {
    repayLoan.value = loan;
    repayForm.reset();
    repayForm.clearErrors();
    repayForm.payment_date = today();
    repayModal.value = true;
}
function saveRepay() {
    repayForm.post(getRoute("finance.loan-payments.store", { loan: repayLoan.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            repayModal.value = false;
            message.success("Repayment recorded");
        },
    });
}

/*** === ASSETS === ***/
const assetModal = ref(false);
const editingAsset = ref(null);
const assetForm = useForm({
    name: "", category: "equipment", purchase_date: today(), cost: null, useful_life_months: 60,
    payment_method: "bank", location_id: props.defaultLocationId, disposed_date: null, notes: "",
});
function openAsset(row = null) {
    editingAsset.value = row;
    assetForm.clearErrors();
    assetForm.reset();
    if (row) {
        Object.assign(assetForm, {
            name: row.name, category: row.category, purchase_date: row.purchase_date, cost: Number(row.cost),
            useful_life_months: row.useful_life_months, payment_method: row.payment_method, location_id: row.location_id,
            disposed_date: row.disposed_date, notes: row.notes ?? "",
        });
    } else assetForm.purchase_date = today();
    assetModal.value = true;
}

/*** === OWNER INVESTMENTS === ***/
const investmentModal = ref(false);
const editingInvestment = ref(null);
const investmentForm = useForm({ investment_date: today(), amount: null, payment_method: "bank", location_id: props.defaultLocationId, notes: "" });
function openInvestment(row = null) {
    editingInvestment.value = row;
    investmentForm.clearErrors();
    investmentForm.reset();
    if (row) {
        Object.assign(investmentForm, {
            investment_date: row.investment_date, amount: Number(row.amount), payment_method: row.payment_method,
            location_id: row.location_id, notes: row.notes ?? "",
        });
    } else investmentForm.investment_date = today();
    investmentModal.value = true;
}

/*** === OTHER LIABILITIES === ***/
const liabilityLabel = { tax: "Tax due", customer_deposit: "Customer deposit", wages: "Wages not yet paid", other: "Other" };
const liabilityModal = ref(false);
const editingLiability = ref(null);
const liabilityForm = useForm({ name: "", category: "tax", amount: null, incurred_date: today(), due_date: null, settled_date: null, notes: "" });
function openLiability(row = null) {
    editingLiability.value = row;
    liabilityForm.clearErrors();
    liabilityForm.reset();
    if (row) {
        Object.assign(liabilityForm, {
            name: row.name, category: row.category, amount: Number(row.amount), incurred_date: row.incurred_date,
            due_date: row.due_date, settled_date: row.settled_date, notes: row.notes ?? "",
        });
    } else liabilityForm.incurred_date = today();
    liabilityModal.value = true;
}
const liabilityColumns = [
    { title: "What", key: "name" },
    { title: "Since", dataIndex: "incurred_date", key: "incurred_date", width: 130, customRender: ({ text }) => fmtDate(text) },
    { title: "Due", dataIndex: "due_date", key: "due_date", width: 130, customRender: ({ text }) => fmtDate(text) },
    { title: "Amount", dataIndex: "amount", key: "amount", align: "right", customRender: ({ text }) => formattedTotal(text) },
    { title: "", key: "actions", width: 90, align: "right" },
];

const money = ({ text }) => (text === null || text === undefined ? "—" : formattedTotal(text));
const accountColumns = [
    { title: "Account", key: "name" },
    { title: "Balance", dataIndex: "balance", key: "balance", align: "right", customRender: money },
    { title: "As of", dataIndex: "as_of", key: "as_of", width: 130, customRender: ({ text }) => fmtDate(text) },
    { title: "", key: "actions", width: 200, align: "right" },
];
const loanColumns = [
    { title: "Lender", key: "lender" },
    { title: "Borrowed", dataIndex: "principal", key: "principal", align: "right", customRender: money },
    { title: "Still owed", dataIndex: "balance", key: "balance", align: "right", customRender: money },
    { title: "Interest paid", dataIndex: "interest_paid", key: "interest_paid", align: "right", customRender: money },
    { title: "", key: "actions", width: 170, align: "right" },
];
const assetColumns = [
    { title: "Asset", key: "name" },
    { title: "Bought", dataIndex: "purchase_date", key: "purchase_date", width: 130, customRender: ({ text }) => fmtDate(text) },
    { title: "Cost", dataIndex: "cost", key: "cost", align: "right", customRender: money },
    { title: "Value now", dataIndex: "book_value", key: "book_value", align: "right", customRender: money },
    { title: "", key: "actions", width: 90, align: "right" },
];
const investmentColumns = [
    { title: "Date", dataIndex: "investment_date", key: "date", width: 130, customRender: ({ text }) => fmtDate(text) },
    { title: "Notes", dataIndex: "notes", key: "notes" },
    { title: "Into", key: "method" },
    { title: "Amount", dataIndex: "amount", key: "amount", align: "right", customRender: money },
    { title: "", key: "actions", width: 90, align: "right" },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Accounts, loans & assets" />
        <ContentHeader class="mb-4 md:mb-6" title="Accounts, loans & assets" />
        <ContentLayout title="Everything else on the balance sheet" filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0">
            <template #filters>
                <RefreshButton :loading="spinning" @click="reload" />
            </template>

            <template #table>
                <div class="space-y-6 px-4 pb-8 md:px-6">
                    <FinanceNav active="finance.balance-items.index" :filters="{ key: 'month' }" />

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <MetricCard label="Bank & e-wallets" :value="totals.accounts" hint="Latest balances you entered" />
                        <MetricCard label="Loans still owed" :value="totals.loans" />
                        <MetricCard label="Equipment & assets (value now)" :value="totals.assets" hint="Cost less depreciation" />
                        <MetricCard label="Owner investments" :value="totals.invested" :hint="`${formattedTotal(totals.withdrawn)} withdrawn by the owner`" />
                    </div>
                    <p v-if="totals.other_liabilities > 0" class="text-sm text-gray-600">
                        Other amounts still owed (taxes, deposits, wages): <strong>{{ formattedTotal(totals.other_liabilities) }}</strong>
                    </p>

                    <a-tabs v-model:activeKey="activeTab" data-testid="balance-items-tabs">
                        <!-- Bank and e-wallet accounts -->
                        <a-tab-pane key="accounts" tab="Bank & e-wallets">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm text-gray-500">Enter the balance you see in the bank or e-wallet app every now and then; the latest one counts.</p>
                                <a-button v-if="hasPermission('finance.accounts.store')" type="primary"
                                    class="flex items-center border border-green-500 bg-white text-green-500" data-testid="add-account" @click="openAccount()">
                                    <template #icon><IconPlus /></template> Add account
                                </a-button>
                            </div>
                            <a-table :columns="accountColumns" :data-source="accounts" :pagination="false" row-key="id" size="small" bordered
                                class="bg-white" :scroll="{ x: 640 }" data-testid="account-table">
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'name'">
                                        <span :class="record.is_active ? 'font-medium text-gray-900' : 'text-gray-400 line-through'">{{ record.name }}</span>
                                        <div class="text-xs text-gray-500">{{ typeLabel[record.type] }}{{ record.account_number ? ` · ${record.account_number}` : "" }}</div>
                                        <div v-if="record.channel" class="text-xs text-blue-600" data-testid="account-channel">
                                            Fed by {{ record.channel }}<template v-if="record.received_since > 0">: +{{ formattedTotal(record.received_since) }} in sales since the last balance</template>
                                        </div>
                                    </template>
                                    <template v-else-if="column.key === 'actions'">
                                        <div class="flex justify-end gap-1">
                                            <a-button v-if="hasPermission('finance.account-balances.store')" size="small" data-testid="update-balance" @click="openBalance(record)">
                                                Update balance
                                            </a-button>
                                            <a-button v-if="hasPermission('finance.accounts.update')" size="small" type="text" @click="openAccount(record)"><IconEdit :size="16" /></a-button>
                                        </div>
                                    </template>
                                </template>
                                <template #expandedRowRender="{ record }">
                                    <div v-if="!record.history.length" class="text-sm text-gray-500">No balances yet.</div>
                                    <div v-for="h in record.history" :key="h.id" class="flex items-center justify-between gap-2 py-1 text-sm">
                                        <span class="text-gray-700">{{ fmtDate(h.as_of_date) }} · {{ formattedTotal(h.balance) }}
                                            <span v-if="h.note" class="text-gray-400"> · {{ h.note }}</span></span>
                                        <a-popconfirm v-if="hasPermission('finance.account-balances.destroy')" title="Delete this balance?" ok-text="Delete"
                                            ok-type="danger" @confirm="remove('finance.account-balances.destroy', { balance: h.id }, 'Balance')">
                                            <a-button size="small" type="link" danger>Delete</a-button>
                                        </a-popconfirm>
                                    </div>
                                </template>
                            </a-table>
                        </a-tab-pane>

                        <!-- Loans -->
                        <a-tab-pane key="loans" tab="Loans">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm text-gray-500">Repayments lower what you owe; the interest part is booked as an expense (Loan interest).</p>
                                <a-button v-if="hasPermission('finance.loans.store')" type="primary"
                                    class="flex items-center border border-green-500 bg-white text-green-500" data-testid="add-loan" @click="openLoan()">
                                    <template #icon><IconPlus /></template> Record loan
                                </a-button>
                            </div>
                            <a-table :columns="loanColumns" :data-source="loans" :pagination="false" row-key="id" size="small" bordered
                                class="bg-white" :scroll="{ x: 720 }" data-testid="loan-table">
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'lender'">
                                        <span class="font-medium text-gray-900">{{ record.lender }}</span>
                                        <div class="text-xs text-gray-500">
                                            Received {{ fmtDate(record.received_date) }}{{ record.due_date ? ` · due ${fmtDate(record.due_date)}` : "" }}{{ record.interest_rate ? ` · ${Number(record.interest_rate)}%/yr` : "" }}
                                        </div>
                                    </template>
                                    <template v-else-if="column.key === 'actions'">
                                        <div class="flex justify-end gap-1">
                                            <a-button v-if="record.balance > 0 && hasPermission('finance.loan-payments.store')" size="small" data-testid="repay-loan" @click="openRepay(record)">Repay</a-button>
                                            <a-button v-if="hasPermission('finance.loans.update')" size="small" type="text" @click="openLoan(record)"><IconEdit :size="16" /></a-button>
                                            <a-popconfirm v-if="hasPermission('finance.loans.destroy')" title="Delete this loan?" ok-text="Delete" ok-type="danger"
                                                @confirm="remove('finance.loans.destroy', { loan: record.id }, 'Loan')">
                                                <a-button size="small" type="text" danger><IconTrash :size="16" /></a-button>
                                            </a-popconfirm>
                                        </div>
                                    </template>
                                </template>
                                <template #expandedRowRender="{ record }">
                                    <div v-if="!record.payments.length" class="text-sm text-gray-500">No repayments yet.</div>
                                    <div v-for="p in record.payments" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-1 text-sm">
                                        <span class="text-gray-700">
                                            {{ fmtDate(p.payment_date) }} · principal {{ formattedTotal(p.principal) }}<template v-if="Number(p.interest) > 0">, interest {{ formattedTotal(p.interest) }}</template>
                                            · {{ paymentMethodLabel(p.payment_method) }}
                                        </span>
                                        <a-popconfirm v-if="hasPermission('finance.loan-payments.destroy')" title="Delete this repayment?" ok-text="Delete"
                                            ok-type="danger" @confirm="remove('finance.loan-payments.destroy', { payment: p.id }, 'Repayment')">
                                            <a-button size="small" type="link" danger>Delete</a-button>
                                        </a-popconfirm>
                                    </div>
                                </template>
                            </a-table>
                        </a-tab-pane>

                        <!-- Equipment and other assets -->
                        <a-tab-pane key="assets" tab="Equipment & assets">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm text-gray-500">Things the business uses, not stock for sale. With a useful life, the cost is spread over it as depreciation.</p>
                                <a-button v-if="hasPermission('finance.assets.store')" type="primary"
                                    class="flex items-center border border-green-500 bg-white text-green-500" data-testid="add-asset" @click="openAsset()">
                                    <template #icon><IconPlus /></template> Record asset
                                </a-button>
                            </div>
                            <a-table :columns="assetColumns" :data-source="assets" :pagination="false" row-key="id" size="small" bordered
                                class="bg-white" :scroll="{ x: 680 }" data-testid="asset-table">
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'name'">
                                        <span :class="record.disposed_date ? 'text-gray-400 line-through' : 'font-medium text-gray-900'">{{ record.name }}</span>
                                        <div class="text-xs text-gray-500">
                                            {{ categoryLabel[record.category] }}{{ record.useful_life_months ? ` · ${record.useful_life_months} months · ${formattedTotal(record.monthly_depreciation)}/month` : " · not depreciated" }}
                                        </div>
                                    </template>
                                    <template v-else-if="column.key === 'actions'">
                                        <div class="flex justify-end gap-1">
                                            <a-button v-if="hasPermission('finance.assets.update')" size="small" type="text" @click="openAsset(record)"><IconEdit :size="16" /></a-button>
                                            <a-popconfirm v-if="hasPermission('finance.assets.destroy')" title="Delete this asset?" ok-text="Delete" ok-type="danger"
                                                @confirm="remove('finance.assets.destroy', { asset: record.id }, 'Asset')">
                                                <a-button size="small" type="text" danger><IconTrash :size="16" /></a-button>
                                            </a-popconfirm>
                                        </div>
                                    </template>
                                </template>
                            </a-table>
                        </a-tab-pane>

                        <!-- Owner investments -->
                        <a-tab-pane key="investments" tab="Owner investments">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm text-gray-500">Money the owner put into the business. Withdrawals are recorded under Cash &amp; wallet.</p>
                                <a-button v-if="hasPermission('finance.owner-investments.store')" type="primary"
                                    class="flex items-center border border-green-500 bg-white text-green-500" data-testid="add-investment" @click="openInvestment()">
                                    <template #icon><IconPlus /></template> Record investment
                                </a-button>
                            </div>
                            <a-table :columns="investmentColumns" :data-source="investments" :pagination="false" row-key="id" size="small" bordered
                                class="bg-white" :scroll="{ x: 600 }" data-testid="investment-table">
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'method'">
                                        {{ paymentMethodLabel(record.payment_method) }}<span v-if="record.location" class="text-gray-400"> · {{ record.location.name }}</span>
                                    </template>
                                    <template v-else-if="column.key === 'actions'">
                                        <div class="flex justify-end gap-1">
                                            <a-button v-if="hasPermission('finance.owner-investments.update')" size="small" type="text" @click="openInvestment(record)"><IconEdit :size="16" /></a-button>
                                            <a-popconfirm v-if="hasPermission('finance.owner-investments.destroy')" title="Delete this investment?" ok-text="Delete"
                                                ok-type="danger" @confirm="remove('finance.owner-investments.destroy', { investment: record.id }, 'Investment')">
                                                <a-button size="small" type="text" danger><IconTrash :size="16" /></a-button>
                                            </a-popconfirm>
                                        </div>
                                    </template>
                                </template>
                            </a-table>
                        </a-tab-pane>
                        <!-- Other liabilities -->
                        <a-tab-pane key="liabilities" tab="Other liabilities">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm text-gray-500">
                                    Other amounts the business owes: taxes due, customer deposits, wages not yet paid. Set the date it was settled once paid.
                                </p>
                                <a-button v-if="hasPermission('finance.other-liabilities.store')" type="primary"
                                    class="flex items-center border border-green-500 bg-white text-green-500" data-testid="add-liability" @click="openLiability()">
                                    <template #icon><IconPlus /></template> Record amount owed
                                </a-button>
                            </div>
                            <a-table :columns="liabilityColumns" :data-source="otherLiabilities" :pagination="false" row-key="id" size="small" bordered
                                class="bg-white" :scroll="{ x: 640 }" data-testid="liability-table">
                                <template #bodyCell="{ column, record }">
                                    <template v-if="column.key === 'name'">
                                        <span :class="record.settled_date ? 'text-gray-400 line-through' : 'font-medium text-gray-900'">{{ record.name }}</span>
                                        <div class="text-xs text-gray-500">
                                            {{ liabilityLabel[record.category] }}{{ record.settled_date ? ` · settled ${fmtDate(record.settled_date)}` : "" }}
                                        </div>
                                    </template>
                                    <template v-else-if="column.key === 'actions'">
                                        <div class="flex justify-end gap-1">
                                            <a-button v-if="hasPermission('finance.other-liabilities.update')" size="small" type="text" @click="openLiability(record)"><IconEdit :size="16" /></a-button>
                                            <a-popconfirm v-if="hasPermission('finance.other-liabilities.destroy')" title="Delete this?" ok-text="Delete" ok-type="danger"
                                                @confirm="remove('finance.other-liabilities.destroy', { liability: record.id }, 'Liability')">
                                                <a-button size="small" type="text" danger><IconTrash :size="16" /></a-button>
                                            </a-popconfirm>
                                        </div>
                                    </template>
                                </template>
                            </a-table>
                        </a-tab-pane>
                    </a-tabs>
                </div>
            </template>
        </ContentLayout>

        <!-- Other liability -->
        <a-modal v-model:visible="liabilityModal" :title="editingLiability ? 'Edit amount owed' : 'Record amount owed'"
            :confirm-loading="liabilityForm.processing" ok-text="Save"
            @ok="submit(liabilityForm, { create: 'finance.other-liabilities.store', update: 'finance.other-liabilities.update', id: editingLiability?.id, idKey: 'liability', label: 'Liability', done: () => (liabilityModal = false) })">
            <a-form layout="vertical" data-testid="liability-form">
                <a-form-item label="What is it?" :validate-status="liabilityForm.errors.name ? 'error' : ''" :help="liabilityForm.errors.name" required>
                    <a-input v-model:value="liabilityForm.name" placeholder="e.g. VAT for September, Deposit from Santos catering" data-testid="liability-name" />
                </a-form-item>
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Kind" required>
                        <a-select v-model:value="liabilityForm.category" :options="liabilityCategories.map((c) => ({ value: c, label: liabilityLabel[c] ?? c }))" />
                    </a-form-item>
                    <a-form-item label="Amount" :validate-status="liabilityForm.errors.amount ? 'error' : ''" :help="liabilityForm.errors.amount" required>
                        <a-input-number v-model:value="liabilityForm.amount" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="liability-amount" />
                    </a-form-item>
                    <a-form-item label="Owed since" :validate-status="liabilityForm.errors.incurred_date ? 'error' : ''" :help="liabilityForm.errors.incurred_date" required>
                        <a-date-picker :value="liabilityForm.incurred_date ? dayjs(liabilityForm.incurred_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (liabilityForm.incurred_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                    <a-form-item label="Due (optional)" :validate-status="liabilityForm.errors.due_date ? 'error' : ''" :help="liabilityForm.errors.due_date">
                        <a-date-picker :value="liabilityForm.due_date ? dayjs(liabilityForm.due_date) : null" class="w-full"
                            @change="(d) => (liabilityForm.due_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <a-form-item v-if="editingLiability" label="Settled on (once paid)" :validate-status="liabilityForm.errors.settled_date ? 'error' : ''"
                    :help="liabilityForm.errors.settled_date">
                    <a-date-picker :value="liabilityForm.settled_date ? dayjs(liabilityForm.settled_date) : null" class="w-full"
                        :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (liabilityForm.settled_date = d ? d.format('YYYY-MM-DD') : null)" />
                </a-form-item>
                <a-form-item label="Notes"><a-input v-model:value="liabilityForm.notes" /></a-form-item>
            </a-form>
        </a-modal>

        <!-- Account -->
        <a-modal v-model:visible="accountModal" :title="editingAccount ? 'Edit account' : 'Add bank or e-wallet account'"
            :confirm-loading="accountForm.processing" ok-text="Save"
            @ok="submit(accountForm, { create: 'finance.accounts.store', update: 'finance.accounts.update', id: editingAccount?.id, idKey: 'account', label: 'Account', done: () => (accountModal = false) })">
            <a-form layout="vertical" data-testid="account-form">
                <a-form-item label="Name" :validate-status="accountForm.errors.name ? 'error' : ''" :help="accountForm.errors.name" required>
                    <a-input v-model:value="accountForm.name" placeholder="e.g. BDO savings, GCash" data-testid="account-name" />
                </a-form-item>
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Type" required>
                        <a-select v-model:value="accountForm.type" :options="[{ value: 'bank', label: 'Bank' }, { value: 'ewallet', label: 'E-wallet' }, { value: 'other', label: 'Other' }]" />
                    </a-form-item>
                    <a-form-item label="Account number (optional)"><a-input v-model:value="accountForm.account_number" /></a-form-item>
                </div>
                <a-form-item label="Customers pay into it through (optional)"
                    :help="accountForm.errors.payment_card_type_id || 'Sales paid through this payment channel are added to the balance automatically, until you enter a new balance.'"
                    :validate-status="accountForm.errors.payment_card_type_id ? 'error' : ''">
                    <a-select v-model:value="accountForm.payment_card_type_id" allow-clear placeholder="Not linked" data-testid="account-channel-select"
                        :options="channels.map((c) => ({ value: c.id, label: c.location ? `${c.name} · ${c.location}` : c.name }))" />
                </a-form-item>
                <div v-if="!editingAccount" class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Current balance" :validate-status="accountForm.errors.balance ? 'error' : ''" :help="accountForm.errors.balance">
                        <a-input-number v-model:value="accountForm.balance" :precision="2" prefix="₱" class="w-full" data-testid="account-balance" />
                    </a-form-item>
                    <a-form-item label="As of">
                        <a-date-picker :value="accountForm.as_of_date ? dayjs(accountForm.as_of_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (accountForm.as_of_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <a-checkbox v-if="editingAccount" v-model:checked="accountForm.is_active">Active</a-checkbox>
            </a-form>
        </a-modal>

        <!-- Account balance -->
        <a-modal v-model:visible="balanceModal" :title="`Update balance: ${balanceAccount?.name ?? ''}`" :confirm-loading="balanceForm.processing"
            ok-text="Save" @ok="saveBalance">
            <a-form layout="vertical" data-testid="balance-form">
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Balance" :validate-status="balanceForm.errors.balance ? 'error' : ''" :help="balanceForm.errors.balance" required>
                        <a-input-number v-model:value="balanceForm.balance" :precision="2" prefix="₱" class="w-full" data-testid="balance-amount" />
                    </a-form-item>
                    <a-form-item label="As of" :validate-status="balanceForm.errors.as_of_date ? 'error' : ''" :help="balanceForm.errors.as_of_date" required>
                        <a-date-picker :value="balanceForm.as_of_date ? dayjs(balanceForm.as_of_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (balanceForm.as_of_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <a-form-item label="Note (optional)"><a-input v-model:value="balanceForm.note" placeholder="e.g. from the bank app" /></a-form-item>
            </a-form>
        </a-modal>

        <!-- Loan -->
        <a-modal v-model:visible="loanModal" :title="editingLoan ? 'Edit loan' : 'Record loan'" :confirm-loading="loanForm.processing" ok-text="Save"
            @ok="submit(loanForm, { create: 'finance.loans.store', update: 'finance.loans.update', id: editingLoan?.id, idKey: 'loan', label: 'Loan', done: () => (loanModal = false) })">
            <a-form layout="vertical" data-testid="loan-form">
                <a-form-item label="Lender" :validate-status="loanForm.errors.lender ? 'error' : ''" :help="loanForm.errors.lender" required>
                    <a-input v-model:value="loanForm.lender" placeholder="e.g. Landbank, a relative" data-testid="loan-lender" />
                </a-form-item>
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Amount borrowed" :validate-status="loanForm.errors.principal ? 'error' : ''" :help="loanForm.errors.principal" required>
                        <a-input-number v-model:value="loanForm.principal" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="loan-principal" />
                    </a-form-item>
                    <a-form-item label="Received on" :validate-status="loanForm.errors.received_date ? 'error' : ''" :help="loanForm.errors.received_date" required>
                        <a-date-picker :value="loanForm.received_date ? dayjs(loanForm.received_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (loanForm.received_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                    <a-form-item label="Interest rate per year (optional)">
                        <a-input-number v-model:value="loanForm.interest_rate" :min="0" :max="100" :precision="2" addon-after="%" class="w-full" />
                    </a-form-item>
                    <a-form-item label="Due date (optional)" :validate-status="loanForm.errors.due_date ? 'error' : ''" :help="loanForm.errors.due_date">
                        <a-date-picker :value="loanForm.due_date ? dayjs(loanForm.due_date) : null" class="w-full"
                            @change="(d) => (loanForm.due_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <PaymentMethodFields :form="loanForm" direction="in" :methods="paymentMethods" :locations="locations" />
                <a-form-item label="Notes"><a-input v-model:value="loanForm.notes" /></a-form-item>
            </a-form>
        </a-modal>

        <!-- Loan repayment -->
        <a-modal v-model:visible="repayModal" :title="`Repay: ${repayLoan?.lender ?? ''}`" :confirm-loading="repayForm.processing" ok-text="Save" @ok="saveRepay">
            <p v-if="repayLoan" class="mb-4 text-sm text-gray-600"><strong>{{ formattedTotal(repayLoan.balance) }}</strong> of this loan is still owed.</p>
            <a-form layout="vertical" data-testid="repay-form">
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
                    <a-form-item label="Principal" :validate-status="repayForm.errors.principal ? 'error' : ''" :help="repayForm.errors.principal" required>
                        <a-input-number v-model:value="repayForm.principal" :min="0" :precision="2" prefix="₱" class="w-full" data-testid="repay-principal" />
                    </a-form-item>
                    <a-form-item label="Interest" :validate-status="repayForm.errors.interest ? 'error' : ''" :help="repayForm.errors.interest">
                        <a-input-number v-model:value="repayForm.interest" :min="0" :precision="2" prefix="₱" class="w-full" data-testid="repay-interest" />
                    </a-form-item>
                    <a-form-item label="Date" :validate-status="repayForm.errors.payment_date ? 'error' : ''" :help="repayForm.errors.payment_date" required>
                        <a-date-picker :value="repayForm.payment_date ? dayjs(repayForm.payment_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (repayForm.payment_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <PaymentMethodFields :form="repayForm" :methods="paymentMethods" :locations="locations" />
                <a-form-item label="Reference (optional)"><a-input v-model:value="repayForm.reference_no" /></a-form-item>
            </a-form>
        </a-modal>

        <!-- Asset -->
        <a-modal v-model:visible="assetModal" :title="editingAsset ? 'Edit asset' : 'Record equipment or other asset'" :confirm-loading="assetForm.processing" ok-text="Save"
            @ok="submit(assetForm, { create: 'finance.assets.store', update: 'finance.assets.update', id: editingAsset?.id, idKey: 'asset', label: 'Asset', done: () => (assetModal = false) })">
            <a-form layout="vertical" data-testid="asset-form">
                <a-form-item label="What is it?" :validate-status="assetForm.errors.name ? 'error' : ''" :help="assetForm.errors.name" required>
                    <a-input v-model:value="assetForm.name" placeholder="e.g. Chest freezer, Delivery motorcycle" data-testid="asset-name" />
                </a-form-item>
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Kind" required>
                        <a-select v-model:value="assetForm.category" :options="assetCategories.map((c) => ({ value: c, label: categoryLabel[c] ?? c }))" />
                    </a-form-item>
                    <a-form-item label="Cost" :validate-status="assetForm.errors.cost ? 'error' : ''" :help="assetForm.errors.cost" required>
                        <a-input-number v-model:value="assetForm.cost" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="asset-cost" />
                    </a-form-item>
                    <a-form-item label="Bought on" :validate-status="assetForm.errors.purchase_date ? 'error' : ''" :help="assetForm.errors.purchase_date" required>
                        <a-date-picker :value="assetForm.purchase_date ? dayjs(assetForm.purchase_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (assetForm.purchase_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                    <a-form-item label="Useful life (months)" :help="assetForm.errors.useful_life_months || 'Leave empty to not depreciate (e.g. land)'"
                        :validate-status="assetForm.errors.useful_life_months ? 'error' : ''">
                        <a-input-number v-model:value="assetForm.useful_life_months" :min="1" :max="600" class="w-full" />
                    </a-form-item>
                </div>
                <PaymentMethodFields :form="assetForm" :methods="paymentMethods" :locations="locations" />
                <a-form-item v-if="editingAsset" label="Sold or thrown away on (optional)">
                    <a-date-picker :value="assetForm.disposed_date ? dayjs(assetForm.disposed_date) : null" class="w-full"
                        @change="(d) => (assetForm.disposed_date = d ? d.format('YYYY-MM-DD') : null)" />
                </a-form-item>
                <a-form-item label="Notes"><a-input v-model:value="assetForm.notes" /></a-form-item>
            </a-form>
        </a-modal>

        <!-- Owner investment -->
        <a-modal v-model:visible="investmentModal" :title="editingInvestment ? 'Edit investment' : 'Record owner investment'"
            :confirm-loading="investmentForm.processing" ok-text="Save"
            @ok="submit(investmentForm, { create: 'finance.owner-investments.store', update: 'finance.owner-investments.update', id: editingInvestment?.id, idKey: 'investment', label: 'Investment', done: () => (investmentModal = false) })">
            <a-form layout="vertical" data-testid="investment-form">
                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                    <a-form-item label="Amount" :validate-status="investmentForm.errors.amount ? 'error' : ''" :help="investmentForm.errors.amount" required>
                        <a-input-number v-model:value="investmentForm.amount" :min="0.01" :precision="2" prefix="₱" class="w-full" data-testid="investment-amount" />
                    </a-form-item>
                    <a-form-item label="Date" :validate-status="investmentForm.errors.investment_date ? 'error' : ''" :help="investmentForm.errors.investment_date" required>
                        <a-date-picker :value="investmentForm.investment_date ? dayjs(investmentForm.investment_date) : null" class="w-full"
                            :disabled-date="(d) => d && d.isAfter(dayjs(), 'day')" @change="(d) => (investmentForm.investment_date = d ? d.format('YYYY-MM-DD') : null)" />
                    </a-form-item>
                </div>
                <PaymentMethodFields :form="investmentForm" direction="in" :methods="paymentMethods" :locations="locations" />
                <a-form-item label="Notes"><a-input v-model:value="investmentForm.notes" placeholder="e.g. Capital for the new branch" /></a-form-item>
            </a-form>
        </a-modal>
    </AuthenticatedLayout>
</template>
