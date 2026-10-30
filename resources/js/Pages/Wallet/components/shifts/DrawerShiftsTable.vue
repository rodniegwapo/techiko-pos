<script setup>
import { computed, ref } from "vue";
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
        expected_cash: props.cashControl.expected_cash,
        in_tray: props.drawerByShift.total,
    }] : []),
]);

const rowProps = (r) => ({
    "data-testid": r.kind === "shift" ? `drawer-shift-${r.id}` : `drawer-shift-${r.kind}`,
    class: r.kind === "total" ? "bg-gray-50 font-semibold" : "",
});
// The whole store's cells keep the ids the "What should be in the drawer" steps had.
const totalId = { opening: "step-opening", sales: "step-sales", in: "step-in", out: "step-out" };
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white" data-testid="drawer-steps">
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-3 py-2">
            <a-radio-group v-model:value="filter" size="small" data-testid="drawer-shift-filter">
                <a-radio-button v-for="f in FILTERS" :key="f.key" :value="f.key" :data-testid="`drawer-shift-filter-${f.key}`">
                    {{ f.label }} ({{ filterCounts[f.key] }})
                </a-radio-button>
            </a-radio-group>
        </div>
        <a-table :columns="columns" :data-source="rows" :pagination="false" :custom-row="rowProps" row-key="rowKey" size="middle" :scroll="{ x: 'max-content' }">
            <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'cashier'">
                    <template v-if="record.kind === 'shift'">
                        <a-button type="link" class="h-auto !px-0 font-medium" :data-testid="`drawer-shift-view-${record.id}`" @click="emit('view', record)">
                            {{ record.user?.name ?? "—" }}
                        </a-button>
                        <div class="whitespace-nowrap text-xs text-gray-500">
                            <template v-if="record.earlier_day">{{ dayjs(record.opened_at).format("MMM D, h:mm A") }} – {{ record.closed_at ? dayjs(record.closed_at).format("MMM D, h:mm A") : "now" }}</template>
                            <template v-else>{{ dayjs(record.opened_at).format("h:mm A") }} – {{ record.closed_at ? dayjs(record.closed_at).format("h:mm A") : "now" }}</template>
                        </div>
                        <a-tag v-if="record.earlier_day" color="orange" class="m-0 mt-1" :data-testid="`drawer-shift-earlier-${record.id}`">
                            {{ record.status === "open" ? "Open since" : "Started" }} {{ dayjs(record.business_date).format("MMM D") }}
                        </a-tag>
                    </template>
                    <a-tooltip
                        v-else-if="record.kind === 'store'"
                        title="Cash in the store's drawer outside any cashier's shift: moved between shifts, added or paid out by a manager with no shift open, and the end-of-day cash-out. Approved counts that came out short or over are booked into the drawer."
                    >
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
                <template v-else-if="['opening', 'sales', 'in', 'out'].includes(column.key)">
                    <span
                        v-if="record.kind !== 'store'"
                        class="font-mono tabular-nums"
                        :data-testid="record.kind === 'total' ? totalId[column.key] : undefined"
                    >
                        {{ column.key === "in" || column.key === "sales" ? "+ " : column.key === "out" ? "− " : "" }}{{ money({ opening: record.opening_cash, sales: record.paid_cash_sales, in: record.manual_in, out: record.manual_out }[column.key]) }}
                    </span>
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
                        {{ shortOver(record.variance).amount ? `${shortOver(record.variance).label} ${money(shortOver(record.variance).amount)}` : shortOver(record.variance).label }}
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
        <p v-if="!cashControl.opening_is_saved && cashControl.suggestion_source_date" class="mb-0 px-3 py-2 text-xs text-amber-700">
            Opening suggested from the count on {{ cashControl.suggestion_source_date }}. Save it with Set opening.
        </p>
    </div>
</template>
