<script setup>
import { computed, reactive } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import AllStoresTable from "@/Pages/Wallet/components/balances/AllStoresTable.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import dayjs from "dayjs";
import { MANUAL_KIND_LABELS, SYSTEM_KIND_LABELS } from "@/Pages/Wallet/components/walletLabels";

/**
 * The warehouse's Cash & Wallet: each store's closed shifts with their last count and the cash
 * taken out at end of shift. Read-only; stores record these on their own Today's drawer page.
 * Above them, the warehouse's cash fund: cash the owner moved here to pay suppliers and expenses.
 */
const props = defineProps({
    storesCash: { type: Object, required: true },
    /** Owners and admins: where each store's money is right now. */
    allStores: { type: Object, default: null },
    /** May start a shift: shifts run at a store, so they're told to switch to one. */
    canStartShift: { type: Boolean, default: false },
    /** { id, name, balance, lines: [{ id, date, direction, amount, kind, notes, by, balance_after }] }, for those who see money. */
    warehouseFund: { type: Object, default: null },
});
const fundLabel = (kind) => SYSTEM_KIND_LABELS[kind] ?? MANUAL_KIND_LABELS[kind] ?? kind;

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const isMdUp = useMediaQuery("(min-width: 768px)");

const filters = reactive({
    date_from: props.storesCash.filters?.date_from ?? "",
    date_to: props.storesCash.filters?.date_to ?? "",
});

const apply = () =>
    router.get(getRoute("wallet.drawer"), { ...filters }, { preserveScroll: true, preserveState: true });

const money = (v) => (v === null || v === undefined ? "—" : formattedTotal(Number(v)));

const columns = [
    { title: "Date", dataIndex: "business_date", key: "business_date" },
    { title: "Store", dataIndex: "store", key: "store" },
    { title: "Counted", key: "counted_cash", align: "right" },
    { title: "Expected", key: "expected_cash", align: "right" },
    { title: "Difference", key: "variance", align: "right" },
    { title: "Cash out", key: "cash_out", align: "right" },
    { title: "Closed by", key: "closed_by" },
];

const rows = computed(() => props.storesCash.rows ?? []);
const totals = computed(() => props.storesCash.totals ?? {});
</script>

<template>
    <Head title="Stores' cash" />
    <AuthenticatedLayout>
        <ContentHeader class="mb-4" title="Stores' cash" />

        <a-alert
            v-if="canStartShift"
            type="info"
            show-icon
            class="mb-4"
            data-testid="warehouse-shift-hint"
            message="You're viewing the warehouse, which has no cash drawer or shifts. To start your shift, switch to a store with the store picker at the top right."
        />

        <!-- The warehouse's cash fund: moved here from Cash on hand, paid out to suppliers and expenses. -->
        <div v-if="warehouseFund" class="mb-6 rounded-lg border border-teal-200 bg-white shadow-sm" data-testid="warehouse-fund">
            <div class="flex flex-wrap items-end justify-between gap-2 border-b border-gray-100 px-4 py-3">
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">{{ warehouseFund.name }} · cash fund</div>
                    <div class="font-mono text-2xl font-semibold tabular-nums" :class="warehouseFund.balance < 0 ? 'text-red-700' : 'text-gray-900'" data-testid="warehouse-fund-balance">
                        {{ money(warehouseFund.balance) }}
                    </div>
                </div>
                <p class="m-0 max-w-md text-xs text-gray-500">
                    Cash the owner moved here from Cash on hand. Pay suppliers and expenses from it: pick this warehouse's cash fund when paying in cash.
                </p>
            </div>
            <p v-if="!warehouseFund.lines.length" class="m-0 px-4 py-3 text-sm text-gray-500">No cash here yet. The owner moves it from Cash on hand.</p>
            <table v-else class="w-full text-sm" data-testid="warehouse-fund-lines">
                <tbody>
                    <tr v-for="l in warehouseFund.lines" :key="l.id" class="border-t border-gray-100 first:border-t-0">
                        <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ dayjs(l.date).format("MMM D") }}</td>
                        <td class="px-4 py-2">
                            <div class="font-medium text-gray-800">{{ fundLabel(l.kind) }}</div>
                            <div v-if="l.notes" class="text-xs text-gray-500">{{ l.notes }}</div>
                        </td>
                        <td class="hidden px-4 py-2 text-gray-500 md:table-cell">{{ l.by ?? "—" }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums" :class="l.direction === 'in' ? 'text-green-700' : 'text-red-700'">
                            {{ l.direction === "in" ? "+" : "−" }} {{ money(l.amount) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums text-gray-700">{{ money(l.balance_after) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4" data-testid="stores-cash-totals">
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Closed shifts</div>
                <div class="text-lg font-bold">{{ totals.shifts ?? 0 }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Counted</div>
                <div class="font-mono text-lg font-bold">{{ money(totals.counted) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Cash out</div>
                <div class="font-mono text-lg font-bold">{{ money(totals.cash_out) }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-xs uppercase text-gray-500">Difference</div>
                <div class="font-mono text-lg font-bold" :class="(totals.variance ?? 0) < 0 ? 'text-red-700' : 'text-gray-900'">
                    {{ money(totals.variance) }}
                </div>
            </div>
        </div>

        <div v-if="allStores" class="mb-6">
            <h3 class="mb-2 text-base font-semibold text-gray-800">Money in each store now</h3>
            <AllStoresTable :all-stores="allStores" />
        </div>

        <ContentLayout title="Closed shifts by store">
            <template #filters>
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label class="mb-1 block text-xs text-gray-600">From</label>
                        <input v-model="filters.date_from" type="date" class="rounded border border-gray-300 px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-gray-600">To</label>
                        <input v-model="filters.date_to" type="date" class="rounded border border-gray-300 px-2 py-1 text-sm" />
                    </div>
                    <a-button type="primary" @click="apply">Apply</a-button>
                </div>
            </template>
            <template #table>
                <p class="mb-3 px-1 text-xs text-gray-500">
                    What each store counted when it closed its shift, and the cash it took out. Stores record these on their own Today's drawer page.
                </p>
                <a-table
                    v-if="isMdUp"
                    :columns="columns"
                    :data-source="rows"
                    row-key="id"
                    :pagination="{ pageSize: 20 }"
                    :locale="{ emptyText: 'No closed shifts in this period.' }"
                    data-testid="stores-cash-table"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'counted_cash'">{{ money(record.counted_cash) }}</template>
                        <template v-else-if="column.key === 'expected_cash'">{{ money(record.expected_cash) }}</template>
                        <template v-else-if="column.key === 'variance'">
                            <span :class="(record.variance ?? 0) < 0 ? 'text-red-700' : ''">{{ money(record.variance) }}</span>
                        </template>
                        <template v-else-if="column.key === 'cash_out'">{{ money(record.cash_out) }}</template>
                        <template v-else-if="column.key === 'closed_by'">
                            {{ record.closed_by || "—" }}
                            <div v-if="record.counted_by" class="text-xs text-gray-500">Counted by {{ record.counted_by }}</div>
                        </template>
                    </template>
                </a-table>
                <div v-else class="flex flex-col gap-3">
                    <p v-if="!rows.length" class="py-8 text-center text-sm text-gray-500">No closed shifts in this period.</p>
                    <div v-for="record in rows" :key="record.id" class="rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm">
                        <div class="flex justify-between font-medium"><span>{{ record.store }}</span><span>{{ record.business_date }}</span></div>
                        <div class="mt-2 grid grid-cols-2 gap-1">
                            <span class="text-gray-500">Counted</span><span class="text-right">{{ money(record.counted_cash) }}</span>
                            <span class="text-gray-500">Expected</span><span class="text-right">{{ money(record.expected_cash) }}</span>
                            <span class="text-gray-500">Difference</span><span class="text-right">{{ money(record.variance) }}</span>
                            <span class="text-gray-500">Cash out</span><span class="text-right">{{ money(record.cash_out) }}</span>
                            <span class="text-gray-500">Closed by</span><span class="text-right">{{ record.closed_by || "—" }}</span>
                        </div>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
