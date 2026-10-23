<script setup>
import { computed, reactive, ref, watch } from "vue";
import dayjs from "dayjs";
import { useMediaQuery, watchDebounced } from "@vueuse/core";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";
import WalletLayout from "./components/WalletLayout.vue";
import CashMovementModal from "./components/cash/CashMovementModal.vue";
import { MANUAL_KIND_LABELS, SYSTEM_KIND_LABELS, autoNoteLabel, movementLabel } from "./components/walletLabels";
import { useWalletQuery } from "./composables/useWalletQuery";

/**
 * Cash in & out: every line in and out of the drawer, the ones added here and the ones other
 * pages post (expenses paid in cash, suppliers paid from the drawer, sales…), plus money moved
 * on payment channels.
 */
const props = defineProps({
    ledger: { type: Object, required: true },
    channels: { type: Array, default: () => [] },
    activeLocation: { type: Object, default: null },
    todayIsClosed: { type: Boolean, default: false },
    canViewAllMoney: { type: Boolean, default: false },
});

const { hasPermission } = usePermissionsV2();
const { formattedTotal } = useHelpers();
const { locationId, visit, todayYmd } = useWalletQuery(() => props.activeLocation);
const isMdUp = useMediaQuery("(min-width: 768px)");

const canAdd = computed(() => hasPermission("wallet-cash-ledger.store"));
const mode = ref(null);

const filters = reactive({ date_from: "", date_to: "", kind: null });
watch(
    () => props.ledger.filters,
    (f) => Object.assign(filters, { date_from: f?.date_from ?? "", date_to: f?.date_to ?? "", kind: f?.kind ?? null }),
    { immediate: true, deep: true },
);

const typeOptions = [
    ...Object.entries(MANUAL_KIND_LABELS).map(([value, label]) => ({ value, label })),
    ...Object.entries(SYSTEM_KIND_LABELS).map(([value, label]) => ({ value, label })),
];

// Filters apply on their own a moment after they change; the page's own filters coming back
// from the server don't count as a change.
watchDebounced(
    () => ({ ...filters }),
    (now) => {
        const f = props.ledger.filters ?? {};
        const same = (now.date_from || null) === (f.date_from ?? null)
            && (now.date_to || null) === (f.date_to ?? null)
            && (now.kind || null) === (f.kind ?? null);
        if (!same) apply();
    },
    { debounce: 400 },
);

function apply(page = 1) {
    visit("wallet.cash-in-out", {
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        kind: filters.kind || undefined,
        page,
    }, {}, { preserveState: true, replace: true });
}

const rows = computed(() => props.ledger.movements?.data ?? []);
const where = (row) => row.payment_card_type?.name ?? "Cash drawer";
const note = (row) => (row.notes && !autoNoteLabel(row.notes) ? row.notes : null);
const signed = (row) => `${row.direction === "out" ? "−" : "+"} ${formattedTotal(Math.abs(Number(row.amount) || 0))}`;
const formatDay = (d) => {
    const day = dayjs(d);
    if (day.format("YYYY-MM-DD") === todayYmd) return "Today";
    if (day.format("YYYY-MM-DD") === dayjs().subtract(1, "day").format("YYYY-MM-DD")) return "Yesterday";
    return day.format("ddd, MMM D, YYYY");
};

const columns = [
    { title: "Date", key: "date", width: 150 },
    { title: "What", key: "what" },
    { title: "Where", key: "where", width: 160 },
    { title: "Amount", key: "amount", align: "right", width: 140 },
    { title: "By", key: "by", width: 120 },
];
const pagination = computed(() => ({
    current: props.ledger.movements?.current_page ?? 1,
    pageSize: props.ledger.movements?.per_page ?? 20,
    total: props.ledger.movements?.total ?? 0,
    showSizeChanger: false,
}));
</script>

<template>
    <WalletLayout title="Cash in & out" active="cash-in-out" :active-location="activeLocation">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs text-gray-600">From</label>
                    <a-date-picker v-model:value="filters.date_from" value-format="YYYY-MM-DD" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-gray-600">To</label>
                    <a-date-picker v-model:value="filters.date_to" value-format="YYYY-MM-DD" />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-gray-600">Type</label>
                    <a-select v-model:value="filters.kind" allow-clear placeholder="All types" class="w-48" :options="typeOptions" data-testid="cash-filter-type" />
                </div>            </div>
            <div v-if="canAdd" class="flex flex-wrap gap-2">
                <a-button type="primary" :disabled="todayIsClosed" data-testid="add-cash-in" @click="mode = 'in'">+ Cash in</a-button>
                <a-button danger :disabled="todayIsClosed" data-testid="add-cash-out" @click="mode = 'out'">− Cash out</a-button>
                <a-button v-if="channels.length" :disabled="todayIsClosed" data-testid="move-money" @click="mode = 'move'">Move money</a-button>
            </div>
        </div>
        <p v-if="canAdd && todayIsClosed" class="-mt-2 mb-3 text-xs text-amber-700">
            Today's shift has ended. Reopen it on Today's drawer to add lines.
        </p>

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <a-table
                v-if="isMdUp"
                :columns="columns"
                :data-source="rows"
                row-key="id"
                :pagination="pagination"
                :locale="{ emptyText: 'Nothing recorded for these dates.' }"
                data-testid="cash-lines"
                @change="(p) => apply(p.current)"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'date'">{{ formatDay(record.movement_date) }}</template>
                    <template v-else-if="column.key === 'what'">
                        <div class="font-medium text-gray-800" data-testid="cash-line-what">{{ movementLabel(record) }}</div>
                        <div v-if="note(record)" class="text-xs text-gray-500">{{ note(record) }}</div>
                    </template>
                    <template v-else-if="column.key === 'where'">{{ where(record) }}</template>
                    <template v-else-if="column.key === 'amount'">
                        <span class="font-mono tabular-nums" :class="record.direction === 'out' ? 'text-red-700' : 'text-green-700'" data-testid="cash-line-amount">
                            {{ signed(record) }}
                        </span>
                    </template>
                    <template v-else-if="column.key === 'by'">{{ record.user?.name ?? "—" }}</template>
                </template>
            </a-table>

            <template v-else>
                <div v-if="!rows.length" class="p-6 text-center text-sm text-gray-500">Nothing recorded for these dates.</div>
                <div v-for="row in rows" :key="row.id" class="flex justify-between gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0" data-testid="cash-line-card">
                    <div class="min-w-0">
                        <div class="font-medium text-gray-800">{{ movementLabel(row) }}</div>
                        <div class="text-xs text-gray-500">{{ formatDay(row.movement_date) }} · {{ where(row) }}<template v-if="row.user"> · {{ row.user.name }}</template></div>
                        <div v-if="note(row)" class="text-xs text-gray-500">{{ note(row) }}</div>
                    </div>
                    <div class="shrink-0 font-mono tabular-nums" :class="row.direction === 'out' ? 'text-red-700' : 'text-green-700'">{{ signed(row) }}</div>
                </div>
                <div v-if="pagination.total > pagination.pageSize" class="flex justify-center p-3">
                    <a-pagination :current="pagination.current" :page-size="pagination.pageSize" :total="pagination.total" size="small" @change="apply" />
                </div>
            </template>
        </div>

        <CashMovementModal :mode="mode" :channels="channels" :location-id="locationId" :today-ymd="todayYmd" @close="mode = null" />
    </WalletLayout>
</template>
