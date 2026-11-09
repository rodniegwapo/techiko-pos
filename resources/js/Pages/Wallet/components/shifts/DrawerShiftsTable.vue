<script setup>
import { computed, ref } from "vue";
import { useMediaQuery } from "@vueuse/core";
import dayjs from "dayjs";
import { useHelpers } from "@/Composables/useHelpers";
import { shortOver, TONE_CLASSES, SHIFT_STATUS } from "../walletLabels";
import ShiftReviewButtons from "./ShiftReviewButtons.vue";
import IconTooltip from "@/Components/buttons/IconTooltip.vue";
import { IconEye, IconPlayerStop } from "@tabler/icons-vue";

/**
 * Today's drawer for supervisors and up: each cashier's shift (opening, cash sales, cash in and
 * out, Expected, the count), the store's cash outside any shift, and the whole store's line, which
 * is how the drawer's Expected is reached. "In tray now" is what each cashier's tray holds; with
 * the store's rest it adds up to that Expected. Ended counts are approved or sent back for a
 * recount right here. A status filter narrows the shifts; a cashier's name opens the shift in full.
 */
const props = defineProps({
    /** { rows: [shift rows with figures and in_tray], store_rest, total } */
    drawerByShift: { type: Object, required: true },
    cashControl: { type: Object, required: true },
    canSeeCountResult: { type: Boolean, default: false },
    canApprove: { type: Boolean, default: false },
    currentUserId: { type: Number, default: null },
    /** Managers and up: the store's rows (outside any shift, whole store). A supervisor sees her team only. */
    wholeStore: { type: Boolean, default: true },
    /** Managers and up: end a forgotten shift for the cashier. */
    canEndForOthers: { type: Boolean, default: false },
});
const emit = defineEmits(["view", "end-for"]);

const { formattedTotal } = useHelpers();
const money = (v) => (v === null || v === undefined ? "—" : formattedTotal(Number(v) || 0));
const isToday = computed(() => props.drawerByShift.store_rest !== null);

const columns = computed(() => [
    { title: "Cashier", key: "cashier" },
    { title: "Status", key: "status" },
    { title: "Opening", key: "opening", align: "right" },
    { title: "Cash sales", key: "sales", align: "right" },
    { title: "Cash in", key: "in", align: "right" },
    { title: "Cash out", key: "out", align: "right" },
    { title: "Expenses", key: "expenses", align: "right" },
    { title: "Expected", key: "expected", align: "right" },
    { title: "Counted", key: "counted", align: "right" },
    ...(props.canSeeCountResult ? [{ title: "Short / Over", key: "result", align: "right" }] : []),
    ...(isToday.value ? [{ title: "In tray now", key: "tray", align: "right" }] : []),
    { title: "", key: "actions", align: "right" },
]);

// All / On shift / Needs review / Ended; the store's and the whole store's rows always show.
const FILTERS = [
    { key: "all", label: "All", match: () => true },
    { key: "open", label: "On shift", match: (r) => r.status === "open" },
    { key: "needs_review", label: "Needs review", match: (r) => r.status === "needs_review" },
    { key: "ended", label: "Ended", match: (r) => ["closed", "approved"].includes(r.status) },
];
const filter = ref("all");
const filterCounts = computed(() => Object.fromEntries(FILTERS.map((f) => [f.key, props.drawerByShift.rows.filter(f.match).length])));
const shownShifts = computed(() => props.drawerByShift.rows.filter(FILTERS.find((f) => f.key === filter.value).match));

const rows = computed(() => [
    ...shownShifts.value.map((r) => ({ ...r, rowKey: `shift-${r.id}`, kind: "shift" })),
    ...(props.wholeStore && isToday.value && props.drawerByShift.store_rest !== 0
        ? [{ rowKey: "store", kind: "store", in_tray: props.drawerByShift.store_rest }]
        : []),
    ...(props.wholeStore ? [{
        rowKey: "total",
        kind: "total",
        opening_cash: props.cashControl.opening_cash,
        paid_cash_sales: props.cashControl.paid_cash_sales,
        manual_in: props.cashControl.manual_in,
        manual_out: props.cashControl.manual_out,
        expenses: props.cashControl.expenses,
        expected_cash: props.cashControl.expected_cash,
        in_tray: props.drawerByShift.total,
    }] : []),
]);

const rowProps = (r) => ({
    "data-testid": r.kind === "shift" ? `drawer-shift-${r.id}` : `drawer-shift-${r.kind}`,
    class: r.kind === "total" ? "bg-gray-50 font-semibold" : "",
});
// The whole store's cells keep the ids the "What should be in the drawer" steps had.
const totalId = { opening: "step-opening", sales: "step-sales", in: "step-in", out: "step-out", expenses: "step-expenses" };

// How Expected is reached, one step per cell: shared by the table and the phone cards.
const STEPS = [
    { key: "opening", label: "Opening", sign: "", field: "opening_cash" },
    { key: "sales", label: "Cash sales", sign: "+ ", field: "paid_cash_sales" },
    { key: "in", label: "Cash in", sign: "+ ", field: "manual_in" },
    { key: "out", label: "Cash out", sign: "− ", field: "manual_out" },
    { key: "expenses", label: "Expenses", sign: "− ", field: "expenses" },
];
const STEP_BY_KEY = Object.fromEntries(STEPS.map((s) => [s.key, s]));
const stepCell = (record, key) => {
    const step = STEP_BY_KEY[key];
    const value = step.field === "expenses" ? record.expenses ?? 0 : record[step.field];
    return `${step.sign}${money(value)}`;
};
const resultText = (record) => {
    const r = shortOver(record.variance);
    if (!r) return "";
    return r.amount ? `${r.label} ${money(r.amount)}` : r.label;
};
const shiftTime = (record) => {
    const fmt = record.earlier_day ? "MMM D, h:mm A" : "h:mm A";
    return `${dayjs(record.opened_at).format(fmt)} – ${record.closed_at ? dayjs(record.closed_at).format(fmt) : "now"}`;
};
const STORE_REST_NOTE = "Cash in the store's drawer outside any cashier's shift: moved between shifts, added or paid out by a manager with no shift open, and the end-of-day cash-out. Approved counts that came out short or over are booked into the drawer.";

// Phones get a card per shift instead of a 12-column table.
const isMdUp = useMediaQuery("(min-width: 768px)");
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white" data-testid="drawer-steps">
        <div class="overflow-x-auto whitespace-nowrap border-b border-gray-100 px-3 py-2">
            <a-radio-group v-model:value="filter" size="small" data-testid="drawer-shift-filter">
                <a-radio-button v-for="f in FILTERS" :key="f.key" :value="f.key" :data-testid="`drawer-shift-filter-${f.key}`">
                    {{ f.label }} ({{ filterCounts[f.key] }})
                </a-radio-button>
            </a-radio-group>
        </div>
        <a-table v-if="isMdUp" :columns="columns" :data-source="rows" :pagination="false" :custom-row="rowProps" row-key="rowKey" size="middle" :scroll="{ x: 'max-content' }">
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'cashier'">
                    <template v-if="record.kind === 'shift'">
                        <a-button type="link" class="h-auto !px-0 font-medium" :data-testid="`drawer-shift-view-${record.id}`" @click="emit('view', record)">
                            {{ record.user?.name ?? "—" }}
                        </a-button>
                        <div class="whitespace-nowrap text-xs text-gray-500">{{ shiftTime(record) }}</div>
                        <a-tag v-if="record.earlier_day" color="orange" class="m-0 mt-1" :data-testid="`drawer-shift-earlier-${record.id}`">
                            {{ record.status === "open" ? "Open since" : "Started" }} {{ dayjs(record.business_date).format("MMM D") }}
                        </a-tag>
                    </template>
                    <a-tooltip v-else-if="record.kind === 'store'" :title="STORE_REST_NOTE">
                        <span class="cursor-help text-gray-500 underline decoration-dotted">Store (not in a shift)</span>
                    </a-tooltip>
                    <span v-else>Whole store</span>
                </template>
                <template v-else-if="column.key === 'status'">
                    <template v-if="record.kind === 'shift'">
                        <a-tag :color="SHIFT_STATUS[record.status]?.color" class="m-0">{{ record.status === "open" ? "On shift" : SHIFT_STATUS[record.status]?.label ?? record.status }}</a-tag>
                        <div v-if="record.reopen_count" class="text-xs text-gray-500">Reopened {{ record.reopen_count }}×</div>
                    </template>
                </template>
                <template v-else-if="['opening', 'sales', 'in', 'out', 'expenses'].includes(column.key)">
                    <span
                        v-if="record.kind !== 'store'"
                        class="font-mono tabular-nums"
                        :data-testid="record.kind === 'total' ? totalId[column.key] : undefined"
                    >{{ stepCell(record, column.key) }}</span>
                </template>
                <template v-else-if="column.key === 'expected'">
                    <span
                        v-if="record.kind !== 'store'"
                        class="font-mono tabular-nums"
                        :class="{ 'font-semibold': record.kind === 'total' }"
                        :data-testid="record.kind === 'total' ? 'drawer-expected' : undefined"
                    >{{ money(record.expected_cash) }}</span>
                </template>
                <template v-else-if="column.key === 'counted'">
                    <template v-if="record.kind === 'shift'">
                        <span class="font-mono tabular-nums">{{ money(record.counted_cash) }}</span>
                        <div v-if="record.status === 'open' && record.last_spot_check" class="whitespace-nowrap text-xs text-gray-500" :data-testid="`drawer-shift-spot-${record.id}`">
                            spot check {{ money(record.last_spot_check.counted_cash) }} · {{ dayjs(record.last_spot_check.at).format("h:mm A") }}
                        </div>
                    </template>
                </template>
                <template v-else-if="column.key === 'result'">
                    <span v-if="record.kind === 'shift' && shortOver(record.variance)" class="font-mono tabular-nums" :class="TONE_CLASSES[shortOver(record.variance).tone]">
                        {{ resultText(record) }}
                    </span>
                </template>
                <template v-else-if="column.key === 'tray'">
                    <span class="font-mono tabular-nums" :data-testid="record.kind === 'shift' ? undefined : `drawer-tray-${record.kind}`">{{ money(record.in_tray) }}</span>
                </template>
                <template v-else-if="column.key === 'actions'">
                    <span v-if="record.kind === 'shift'" class="inline-flex justify-end gap-2">
                        <IconTooltip name="View shift" hover="hover:bg-blue-500" :data-testid="`drawer-shift-open-${record.id}`" @click="emit('view', record)">
                            <IconEye size="18" class="mx-auto" />
                        </IconTooltip>
                        <IconTooltip
                            v-if="record.overdue && canEndForOthers"
                            name="End for them"
                            hover="hover:bg-red-600"
                            :data-testid="`drawer-shift-end-for-${record.id}`"
                            @click="emit('end-for', record)"
                        >
                            <IconPlayerStop size="18" class="mx-auto" />
                        </IconTooltip>
                        <ShiftReviewButtons :shift="record" :can-approve="canApprove" :current-user-id="currentUserId" />
                    </span>
                </template>
            </template>
        </a-table>

        <!-- Phones: one card per shift, then the store's rest and the whole store -->
        <div v-else class="flex flex-col gap-3 p-3" data-testid="drawer-shift-cards">
            <p v-if="!shownShifts.length" class="m-0 py-4 text-center text-sm text-gray-500">No shifts here.</p>
            <template v-for="record in rows" :key="record.rowKey">
                <div
                    v-if="record.kind === 'shift'"
                    class="rounded-lg border border-gray-200 bg-white shadow-sm"
                    :data-testid="`drawer-shift-${record.id}`"
                >
                    <div class="flex items-start justify-between gap-3 px-3 pt-3">
                        <div class="min-w-0">
                            <a-button
                                type="link"
                                class="block h-auto max-w-full whitespace-normal break-words !p-0 text-left text-base font-semibold"
                                :data-testid="`drawer-shift-view-${record.id}`"
                                @click="emit('view', record)"
                            >
                                {{ record.user?.name ?? "—" }}
                            </a-button>
                            <div class="text-xs text-gray-500">{{ shiftTime(record) }}</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <a-tag :color="SHIFT_STATUS[record.status]?.color" class="m-0">{{ record.status === "open" ? "On shift" : SHIFT_STATUS[record.status]?.label ?? record.status }}</a-tag>
                                <a-tag v-if="record.earlier_day" color="orange" class="m-0" :data-testid="`drawer-shift-earlier-${record.id}`">
                                    {{ record.status === "open" ? "Open since" : "Started" }} {{ dayjs(record.business_date).format("MMM D") }}
                                </a-tag>
                                <span v-if="record.reopen_count" class="text-xs text-gray-500">Reopened {{ record.reopen_count }}×</span>
                            </div>
                        </div>
                        <span
                            v-if="canSeeCountResult && shortOver(record.variance)"
                            class="shrink-0 text-right font-mono text-sm font-semibold tabular-nums"
                            :class="TONE_CLASSES[shortOver(record.variance).tone]"
                        >{{ resultText(record) }}</span>
                    </div>
                    <div class="m-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-gray-50 p-3 text-sm">
                        <template v-for="step in STEPS" :key="step.key">
                            <span class="text-gray-500">{{ step.label }}</span>
                            <span class="text-right font-mono tabular-nums">{{ stepCell(record, step.key) }}</span>
                        </template>
                        <span class="border-t border-gray-200 pt-1 font-semibold text-gray-800">Expected</span>
                        <span class="border-t border-gray-200 pt-1 text-right font-mono font-semibold tabular-nums">{{ money(record.expected_cash) }}</span>
                        <span class="text-gray-500">Counted</span>
                        <span class="text-right">
                            <span class="font-mono tabular-nums">{{ money(record.counted_cash) }}</span>
                            <span v-if="record.status === 'open' && record.last_spot_check" class="block text-xs text-gray-500" :data-testid="`drawer-shift-spot-${record.id}`">
                                spot check {{ money(record.last_spot_check.counted_cash) }} · {{ dayjs(record.last_spot_check.at).format("h:mm A") }}
                            </span>
                        </span>
                        <template v-if="isToday">
                            <span class="text-gray-500">In tray now</span>
                            <span class="text-right font-mono tabular-nums">{{ money(record.in_tray) }}</span>
                        </template>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-3 py-2">
                        <a-button size="small" :data-testid="`drawer-shift-open-${record.id}`" @click="emit('view', record)">View shift</a-button>
                        <a-button
                            v-if="record.overdue && canEndForOthers"
                            size="small"
                            danger
                            :data-testid="`drawer-shift-end-for-${record.id}`"
                            @click="emit('end-for', record)"
                        >
                            End for them
                        </a-button>
                        <ShiftReviewButtons :shift="record" :can-approve="canApprove" :current-user-id="currentUserId" />
                    </div>
                </div>

                <div
                    v-else-if="record.kind === 'store'"
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-2"
                    data-testid="drawer-shift-store"
                >
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="text-gray-600">Store (not in a shift)</span>
                        <span class="font-mono tabular-nums" data-testid="drawer-tray-store">{{ money(record.in_tray) }}</span>
                    </div>
                    <p class="m-0 mt-1 text-xs text-gray-500">{{ STORE_REST_NOTE }}</p>
                </div>

                <div v-else class="rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm" data-testid="drawer-shift-total">
                    <div class="mb-2 font-semibold text-gray-800">Whole store</div>
                    <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        <template v-for="step in STEPS" :key="step.key">
                            <span class="text-gray-500">{{ step.label }}</span>
                            <span class="text-right font-mono tabular-nums" :data-testid="totalId[step.key]">{{ stepCell(record, step.key) }}</span>
                        </template>
                        <span class="border-t border-gray-200 pt-1 font-semibold text-gray-800">Expected</span>
                        <span class="border-t border-gray-200 pt-1 text-right font-mono font-semibold tabular-nums" data-testid="drawer-expected">{{ money(record.expected_cash) }}</span>
                        <span class="text-gray-500">In tray now</span>
                        <span class="text-right font-mono tabular-nums" data-testid="drawer-tray-total">{{ money(record.in_tray) }}</span>
                    </div>
                </div>
            </template>
        </div>

        <p v-if="!cashControl.opening_is_saved && cashControl.suggestion_source_date" class="mb-0 px-3 py-2 text-xs text-amber-700">
            Opening suggested from the count on {{ cashControl.suggestion_source_date }}. Save it with Set opening.
        </p>
    </div>
</template>
