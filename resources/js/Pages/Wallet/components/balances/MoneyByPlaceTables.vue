<script setup>
import { computed, ref } from "vue";
import dayjs from "dayjs";
import { useHelpers } from "@/Composables/useHelpers";
import { CHANNEL_KIND_LABELS, SHIFT_STATUS, groupPlaces } from "../walletLabels";
import { useWalletQuery } from "../../composables/useWalletQuery";

/**
 * Where money is, for supervisors and up. By place: the drawer and each payment channel with its
 * balance; open a row to see who it came from (each cashier, and what isn't from a cashier that
 * day). By cashier: each person's money across the places. The split is for one day, today unless
 * another is picked; balances are always today's.
 */
const props = defineProps({
    /** { date, is_today, places: [{ key, name, kind, channel, balance, split, rest }], cashiers, store, columns } */
    moneyByPlace: { type: Object, required: true },
    canAdjust: { type: Boolean, default: false },
    activeLocation: { type: Object, default: null },
});
const emit = defineEmits(["adjust"]);

const { formattedTotal } = useHelpers();
const { visit, todayYmd } = useWalletQuery(() => props.activeLocation);

const view = ref("place");
const money = (v) => formattedTotal(Number(v) || 0);
const statusLabel = (s) => (s === "open" ? "On shift" : SHIFT_STATUS[s]?.label ?? null);
const kindLabel = (p) => (p.kind === "drawer" ? "Cash drawer" : CHANNEL_KIND_LABELS[p.kind] ?? p.kind);

const isToday = computed(() => props.moneyByPlace.is_today);
const dayLabel = computed(() => (isToday.value ? "today" : dayjs(props.moneyByPlace.date).format("MMM D")));
function pickDate(value) {
    visit("wallet.balances", { date: value && value !== todayYmd ? value : undefined });
}
const isFuture = (current) => !!current && current.format("YYYY-MM-DD") > todayYmd;

const placeColumns = [
    { title: "Place", key: "name" },
    { title: "Kind", key: "kind" },
    { title: "Balance", key: "balance", align: "right" },
    { title: "", key: "actions", align: "right" },
];
// Drawer and channel rows keep the test ids the cards had.
const placeRowProps = (record) => ({
    "data-testid": record.key === "drawer" ? "drawer-balance" : `channel-balance-${record.channel.id}`,
});
const expandedKeys = ref([]);
const placeGroups = computed(() => groupPlaces(props.moneyByPlace.places));

const cashierColumns = computed(() => [
    { title: "Cashier", key: "cashier", fixed: "left" },
    { title: "Status", key: "status" },
    ...props.moneyByPlace.columns.map((c) => ({ title: c.name, key: c.key, align: "right" })),
    { title: "Credit (utang)", key: "credit", align: "right" },
    { title: "Total", key: "total", align: "right" },
]);
const cashierRows = computed(() => [
    ...props.moneyByPlace.cashiers.map((c) => ({ ...c, rowKey: `user-${c.user.id}` })),
    ...(props.moneyByPlace.store && props.moneyByPlace.store.drawer !== 0
        ? [{ rowKey: "store", store: true, cells: props.moneyByPlace.store, credit: 0, total: props.moneyByPlace.store.drawer }]
        : []),
]);
</script>

<template>
    <div data-testid="money-by-place">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <a-radio-group v-model:value="view" button-style="solid" size="small" data-testid="money-view">
                <a-radio-button value="place" data-testid="money-view-place">By place</a-radio-button>
                <a-radio-button value="cashier" data-testid="money-view-cashier">By cashier</a-radio-button>
            </a-radio-group>
            <div class="flex items-center gap-2 text-sm text-gray-600">
                <span>Who it came from on</span>
                <a-date-picker
                    :value="moneyByPlace.date"
                    value-format="YYYY-MM-DD"
                    :allow-clear="false"
                    :disabled-date="isFuture"
                    size="small"
                    data-testid="money-date"
                    @change="pickDate"
                />
            </div>
        </div>

        <div v-if="view === 'place'" class="flex flex-col gap-4">
            <section
                v-for="group in placeGroups"
                :key="group.key"
                class="overflow-x-auto rounded-lg border border-gray-200 bg-white"
                :data-testid="`place-group-${group.key}`"
            >
                <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-4 py-2">
                    <span class="text-sm font-semibold text-gray-800">{{ group.label }}</span>
                    <span class="text-xs text-gray-500">Subtotal <span class="ml-1 font-mono text-sm font-semibold tabular-nums text-gray-900" :data-testid="`place-group-subtotal-${group.key}`">{{ money(group.subtotal) }}</span></span>
                </div>
                <a-table
                    v-model:expandedRowKeys="expandedKeys"
                    :columns="placeColumns"
                    :data-source="group.places"
                    :pagination="false"
                    :custom-row="placeRowProps"
                    row-key="key"
                    size="middle"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'name'">
                            <span class="font-medium text-gray-800">{{ record.name }}</span>
                            <span v-if="record.channel && !record.channel.is_active" class="ml-1 text-xs text-gray-400">inactive</span>
                        </template>
                        <template v-else-if="column.key === 'kind'"><span class="text-gray-500">{{ kindLabel(record) }}</span></template>
                        <template v-else-if="column.key === 'balance'">
                            <span
                                class="font-mono font-semibold tabular-nums"
                                :class="record.balance >= 0 ? 'text-gray-900' : 'text-red-700'"
                                :data-testid="record.key === 'drawer' ? 'drawer-balance-amount' : 'channel-balance-amount'"
                            >{{ money(record.balance) }}</span>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <a-button v-if="record.key !== 'drawer' && canAdjust" size="small" type="link" class="!px-0" @click.stop="emit('adjust', record.channel)">Set actual</a-button>
                        </template>
                    </template>
                    <template #expandedRowRender="{ record }">
                        <div class="max-w-xl text-sm" :data-testid="`place-split-${record.key}`">
                            <div class="mb-1 text-xs text-gray-500">
                                {{ record.key === "drawer" && isToday ? "In each cashier's tray now" : `Who it came from, ${dayLabel}` }}
                            </div>
                            <div
                                v-for="row in record.split"
                                :key="row.user.id"
                                class="flex justify-between gap-3 border-b border-gray-100 py-1"
                                :data-testid="`place-split-${record.key}-${row.user.id}`"
                            >
                                <span>
                                    {{ row.user.name }}
                                    <span v-if="statusLabel(row.status)" class="text-xs text-gray-500">· {{ statusLabel(row.status) }}</span>
                                </span>
                                <span class="font-mono tabular-nums">{{ money(row.amount) }}</span>
                            </div>
                            <div v-if="record.rest.amount !== 0" class="flex justify-between gap-3 py-1 text-gray-500" :data-testid="`place-rest-${record.key}`">
                                <a-tooltip
                                    :title="record.key === 'drawer' && isToday
                                        ? 'Cash in the store\'s drawer outside any cashier\'s shift: moved between shifts, added or paid out by a manager with no shift open, and the end-of-day cash-out. Approved counts that came out short or over are booked into the drawer.'
                                        : 'Money here that didn\'t come from a cashier\'s sales on this day: earlier days, transfers, payments and corrections.'"
                                >
                                    <span class="cursor-help underline decoration-dotted">{{ record.rest.label }}</span>
                                </a-tooltip>
                                <span class="font-mono tabular-nums">{{ money(record.rest.amount) }}</span>
                            </div>
                            <p v-if="!record.split.length && record.rest.amount === 0" class="m-0 py-1 text-gray-500">Nothing here.</p>
                        </div>
                    </template>
                </a-table>
            </section>
        </div>

        <div v-else class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <a-table
                :columns="cashierColumns"
                :data-source="cashierRows"
                :pagination="false"
                :custom-row="(r) => ({ 'data-testid': r.store ? 'cashier-row-store' : `cashier-row-${r.user.id}` })"
                row-key="rowKey"
                size="middle"
                :scroll="{ x: 'max-content' }"
            >
                <template #emptyText>
                    <div class="py-6 text-sm text-gray-500">No cashier took money {{ dayLabel }}.</div>
                </template>
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'cashier'">
                        <a-tooltip v-if="record.store" title="Cash in the store's drawer outside any cashier's shift.">
                            <span class="cursor-help text-gray-500 underline decoration-dotted">Store (not in a shift)</span>
                        </a-tooltip>
                        <span v-else class="font-medium text-gray-800">{{ record.user.name }}</span>
                    </template>
                    <template v-else-if="column.key === 'status'">
                        <span v-if="!record.store && statusLabel(record.status)" class="text-xs text-gray-500">{{ statusLabel(record.status) }}</span>
                    </template>
                    <template v-else-if="column.key === 'credit' || column.key === 'total'">
                        <span class="font-mono tabular-nums" :class="{ 'font-semibold': column.key === 'total' }">{{ record[column.key] ? money(record[column.key]) : "—" }}</span>
                    </template>
                    <template v-else>
                        <span class="font-mono tabular-nums" :data-testid="`cell-${column.key}`">{{ record.cells?.[column.key] ? money(record.cells[column.key]) : "—" }}</span>
                    </template>
                </template>
            </a-table>
            <p class="mb-0 px-3 py-2 text-xs text-gray-500">
                <template v-if="isToday">Cash drawer: what's in each cashier's tray now.</template>
                <template v-else>Cash drawer: each cashier's cash sales on {{ dayLabel }}.</template>
                Other places: their sales into each on {{ dayLabel }}.
            </p>
        </div>
    </div>
</template>
