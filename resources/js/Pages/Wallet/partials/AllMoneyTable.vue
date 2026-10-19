<script setup>
import { computed, reactive, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * Every peso in and out for this store, read-only: the drawer and payment-channel ledger plus bank,
 * e-wallet, card and other payments recorded elsewhere (expenses, supplier bills, sales, credit...).
 */
const props = defineProps({
    allMoney: { type: Object, required: true },
    /** Query params to keep on every visit (store, business date). */
    keepQuery: { type: Object, default: () => ({}) },
});

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const SOURCE_LABELS = {
    expense: "Expense",
    supplier_payment: "Supplier bill",
    other_income: "Other income",
    loan: "Loan received",
    loan_payment: "Loan repayment",
    asset: "Equipment bought",
    investment: "Owner investment",
    sale: "Sale",
    sale_part: "Sale",
    credit_payment: "Credit payment",
};

const LEDGER_KIND_LABELS = {
    cash_sale_topup: "Cash / float top-up",
    owner_draw: "Owner withdrawal",
    ewallet_transfer_in: "Transfer in (e-wallet)",
    ewallet_transfer_out: "Transfer out (e-wallet)",
    adjustment: "Adjustment",
    expense: "Expense",
    supplier_payment: "Supplier payment",
    other_income: "Other income",
    loan_received: "Loan received",
    loan_payment: "Loan repayment",
    asset_purchase: "Equipment bought",
    owner_investment: "Owner investment",
    sale_payment: "Sale",
    sale_void: "Sale voided",
    credit_payment: "Credit payment",
};

const METHOD_WHERE_LABELS = {
    bank: "Bank",
    ewallet: "E-wallet",
    card: "Card",
    cash: "Cash (not in drawer ledger)",
    other: "Other",
};

/** The page that owns each kind of row. */
const SOURCE_ROUTES = {
    expense: ["expenses.index"],
    supplier_payment: ["finance.payables.index", { status: "all" }],
    other_income: ["finance.other-income.index"],
    loan: ["finance.balance-items.index", { tab: "loans" }],
    loan_payment: ["finance.balance-items.index", { tab: "loans" }],
    asset: ["finance.balance-items.index", { tab: "assets" }],
    investment: ["finance.balance-items.index", { tab: "investments" }],
    sale: ["sales-history.index"],
    sale_part: ["sales-history.index"],
    credit_payment: ["credits.index"],
};

const typeLabel = (row) =>
    row.source === "ledger" ? LEDGER_KIND_LABELS[row.kind] ?? "Cash movement" : SOURCE_LABELS[row.source] ?? row.source;

const AUTO_NOTE_LABELS = { AUTO_CC_ENDSHIFT_CASHOUT: "End shift cash out" };
const rowLabel = (row) => AUTO_NOTE_LABELS[row.label] ?? (row.label || typeLabel(row));

const whereLabel = (row) => {
    if (row.where_type === "drawer") return "Cash drawer";
    if (row.where_type === "channel" || row.where_type === "account") return row.where_name ?? "—";
    return METHOD_WHERE_LABELS[row.where_type] ?? row.where_type;
};

const sourceLink = (row) => {
    const target = SOURCE_ROUTES[row.source];
    if (!target) return null;
    try {
        const url = getRoute(target[0]);
        if (!url || url === "#") return null;
        const query = new URLSearchParams(target[1] ?? {}).toString();
        return query ? `${url}?${query}` : url;
    } catch {
        return null;
    }
};

const filterForm = reactive({
    date_from: "",
    date_to: "",
    direction: null,
    where: null,
    business_wide: true,
});

watch(
    () => props.allMoney.filters,
    (f) => {
        filterForm.date_from = f?.date_from ?? "";
        filterForm.date_to = f?.date_to ?? "";
        filterForm.direction = f?.direction ?? null;
        filterForm.where = f?.where ?? null;
        filterForm.business_wide = f?.business_wide ?? true;
    },
    { immediate: true },
);

function visit(overrides = {}) {
    router.get(
        getRoute("wallet.money-movement"),
        {
            ...props.keepQuery,
            view: "all",
            date_from: filterForm.date_from || undefined,
            date_to: filterForm.date_to || undefined,
            direction: filterForm.direction || undefined,
            where: filterForm.where || undefined,
            business_wide: filterForm.business_wide ? undefined : 0,
            page: 1,
            ...overrides,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const directionOptions = [
    { value: "in", label: "Money in" },
    { value: "out", label: "Money out" },
];

const columns = [
    { title: "Date", dataIndex: "date", key: "date", width: 110 },
    { title: "What", key: "what" },
    { title: "Where", key: "where", width: 200 },
    { title: "In", key: "in", align: "right", width: 130 },
    { title: "Out", key: "out", align: "right", width: 130 },
];

const rows = computed(() => props.allMoney.rows?.data ?? []);
const pagination = computed(() => ({
    current: props.allMoney.rows?.current_page ?? 1,
    pageSize: props.allMoney.rows?.per_page ?? 20,
    total: props.allMoney.rows?.total ?? 0,
    showSizeChanger: false,
}));
</script>

<template>
    <div class="w-full min-w-0 max-w-7xl space-y-4" data-testid="all-money">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
            <p class="mb-3 text-xs text-gray-600">
                Every peso in and out: the cash drawer and payment channels, plus bank, e-wallet and card payments
                recorded in Expenses, Supplier bills, Sales, Credit and Finance. Read-only here; change a row where it
                was recorded. Drawer counts use the drawer only.
            </p>
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div class="grid min-w-0 flex-1 grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:max-w-3xl lg:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">From</label>
                        <input v-model="filterForm.date_from" type="date" class="w-full rounded border border-gray-300 px-2 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">To</label>
                        <input v-model="filterForm.date_to" type="date" class="w-full rounded border border-gray-300 px-2 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Where</label>
                        <a-select v-model:value="filterForm.where" allow-clear placeholder="Everywhere" class="w-full"
                            :options="allMoney.whereOptions" option-filter-prop="label" show-search data-testid="all-money-where" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">In / out</label>
                        <a-select v-model:value="filterForm.direction" allow-clear placeholder="Both" class="w-full" :options="directionOptions" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-2">
                        <a-checkbox v-model:checked="filterForm.business_wide" data-testid="all-money-business-wide" />
                        Include business-wide (not tied to a store)
                    </label>
                    <div class="sm:col-span-2 lg:col-span-2 flex items-end justify-end">
                        <a-button type="primary" data-testid="all-money-apply" @click="visit()">Apply</a-button>
                    </div>
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-2 border-t border-gray-100 pt-3 xl:border-0 xl:pt-0" data-testid="all-money-totals">
                    <div>
                        <div class="text-xs font-medium uppercase text-gray-500">Money in</div>
                        <div class="font-mono text-lg font-bold tabular-nums text-green-700">{{ formattedTotal(allMoney.totals.in) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase text-gray-500">Money out</div>
                        <div class="font-mono text-lg font-bold tabular-nums text-red-700">{{ formattedTotal(allMoney.totals.out) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase text-gray-500">Net</div>
                        <div class="font-mono text-lg font-bold tabular-nums" :class="allMoney.totals.net >= 0 ? 'text-green-700' : 'text-red-700'">
                            {{ formattedTotal(allMoney.totals.net) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <a-table :columns="columns" :data-source="rows" :pagination="pagination" row-key="key" size="small" bordered
            class="bg-white" :scroll="{ x: 720 }" data-testid="all-money-table"
            @change="(p) => visit({ page: p.current })">
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'what'">
                    <a-tag class="mr-1">{{ typeLabel(record) }}</a-tag>
                    <Link v-if="sourceLink(record)" :href="sourceLink(record)" class="text-gray-800 hover:underline">
                        {{ rowLabel(record) }}
                    </Link>
                    <span v-else class="text-gray-800">{{ rowLabel(record) }}</span>
                    <span v-if="record.location_id === null" class="ml-1 text-xs text-gray-400">· Business-wide</span>
                </template>
                <template v-else-if="column.key === 'where'">
                    {{ whereLabel(record) }}
                </template>
                <template v-else-if="column.key === 'in'">
                    <span v-if="record.direction === 'in'" class="font-mono tabular-nums text-green-700">{{ formattedTotal(record.amount) }}</span>
                </template>
                <template v-else-if="column.key === 'out'">
                    <span v-if="record.direction === 'out'" class="font-mono tabular-nums text-red-700">{{ formattedTotal(record.amount) }}</span>
                </template>
            </template>
        </a-table>
    </div>
</template>
