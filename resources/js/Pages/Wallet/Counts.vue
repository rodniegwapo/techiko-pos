<script setup>
import { computed, ref } from "vue";
import dayjs from "dayjs";
import { useHelpers } from "@/Composables/useHelpers";
import WalletLayout from "./components/WalletLayout.vue";
import StoreCountsByDay from "./components/history/StoreCountsByDay.vue";
import ShiftReviewButtons from "./components/shifts/ShiftReviewButtons.vue";
import ShiftDetailPanel from "./components/shifts/ShiftDetailPanel.vue";
import IconTooltip from "@/Components/buttons/IconTooltip.vue";
import { IconEye } from "@tabler/icons-vue";
import { shortOver, TONE_CLASSES, SHIFT_STATUS } from "./components/walletLabels";
import { useWalletQuery } from "./composables/useWalletQuery";

/**
 * Shifts & counts, for checking back.
 * - Cashier shifts: each shift with its count and how far it was from Expected. The ones that came
 *   out short or over wait here for a manager, who looks into them and approves or sends them back
 *   for a recount. (The owner collects approved shifts' cash on Cash on hand.)
 * - Store counts: the store's counts and opening changes by day, and the days it ended.
 */
const props = defineProps({
    /** shifts | store */
    view: { type: String, default: "shifts" },
    canSeeShifts: { type: Boolean, default: true },
    canSeeStore: { type: Boolean, default: false },
    status: { type: String, default: "needs_review" },
    shifts: { type: Object, default: null },
    statusCounts: { type: Object, default: () => ({}) },
    canApprove: { type: Boolean, default: false },
    currentUserId: { type: Number, default: null },
    /** Owners and admins: each shift's store, as they approve across stores. */
    showStore: { type: Boolean, default: false },
    /** Store counts view. */
    cashControl: { type: Object, default: null },
    closedShifts: { type: Object, default: null },
    activeLocation: { type: Object, default: null },
});

const { formattedTotal } = useHelpers();
const { visit } = useWalletQuery(() => props.activeLocation);
const money = (v) => (v === null || v === undefined ? "—" : formattedTotal(Number(v) || 0));
const time = (at) => (at ? dayjs(at).format("h:mm A") : "—");

const FILTERS = [
    { key: "needs_review", label: "Needs review" },
    { key: "closed", label: "Ended" },
    { key: "approved", label: "Approved" },
    { key: "open", label: "Open" },
    { key: "all", label: "All" },
];
const filterLabel = (f) => {
    const n = f.key === "all" ? null : Number(props.statusCounts?.[f.key] || 0);
    return n ? `${f.label} (${n})` : f.label;
};

const columns = computed(() => [
    { title: "Date", key: "date" },
    { title: "Cashier", key: "cashier" },
    ...(props.showStore ? [{ title: "Store", key: "store" }] : []),
    { title: "Time", key: "time" },
    { title: "Opening", key: "opening", align: "right" },
    { title: "Expected", key: "expected", align: "right" },
    { title: "Counted", key: "counted", align: "right" },
    { title: "Short / Over", key: "result", align: "right" },
    { title: "Status", key: "status" },
    { title: "", key: "actions", align: "right" },
]);

const pagination = computed(() => ({
    current: props.shifts?.current_page,
    pageSize: props.shifts?.per_page,
    total: props.shifts?.total,
    showSizeChanger: false,
}));
function changePage(p) {
    visit("register-shifts.index", { status: props.status, page: p.current });
}

// View: the shift in full, in the side panel.
const viewing = ref(null);
</script>

<template>
    <WalletLayout title="Shifts & counts" active="counts" :active-location="activeLocation">
        <a-radio-group
            v-if="canSeeShifts && canSeeStore"
            :value="view"
            class="mb-4"
            data-testid="counts-view"
            @change="(e) => visit('register-shifts.index', { view: e.target.value === 'store' ? 'store' : undefined })"
        >
            <a-radio-button value="shifts" data-testid="counts-view-shifts">Cashier shifts</a-radio-button>
            <a-radio-button value="store" data-testid="counts-view-store">Store counts</a-radio-button>
        </a-radio-group>

        <StoreCountsByDay
            v-if="view === 'store'"
            :cash-control="cashControl"
            :closed-shifts="closedShifts"
            :active-location="activeLocation"
        />

        <template v-else>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <a-radio-group
                    :value="status"
                    button-style="solid"
                    data-testid="counts-filter"
                    @change="(e) => visit('register-shifts.index', { status: e.target.value })"
                >
                    <a-radio-button v-for="f in FILTERS" :key="f.key" :value="f.key" :data-testid="`counts-filter-${f.key}`">
                        {{ filterLabel(f) }}
                    </a-radio-button>
                </a-radio-group>
                <span v-if="activeLocation" class="text-sm text-gray-500">{{ activeLocation.name }}</span>
            </div>

            <p v-if="status === 'needs_review'" class="mb-3 text-sm text-gray-600">
                Shifts whose count came out short or over. The cashier could keep working; look into each one, then approve it.
            </p>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <a-table
                    :columns="columns"
                    :data-source="shifts.data"
                    :pagination="pagination"
                    row-key="id"
                    size="middle"
                    data-testid="counts-table"
                    @change="changePage"
                >
                    <template #emptyText>
                        <div class="py-6 text-sm text-gray-500">
                            {{ status === "needs_review" ? "Nothing to review: every count matched." : "No shifts here." }}
                        </div>
                    </template>
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'date'">{{ dayjs(record.business_date).format("MMM D, YYYY") }}</template>
                        <template v-else-if="column.key === 'cashier'">{{ record.user?.name ?? "—" }}</template>
                        <template v-else-if="column.key === 'store'"><span :data-testid="`count-store-${record.id}`">{{ record.store ?? "—" }}</span></template>
                        <template v-else-if="column.key === 'time'">
                            <span class="whitespace-nowrap">{{ time(record.opened_at) }} – {{ record.closed_at ? time(record.closed_at) : "now" }}</span>
                        </template>
                        <template v-else-if="column.key === 'opening'"><span class="font-mono tabular-nums">{{ money(record.opening_cash) }}</span></template>
                        <template v-else-if="column.key === 'expected'"><span class="font-mono tabular-nums">{{ money(record.expected_cash) }}</span></template>
                        <template v-else-if="column.key === 'counted'"><span class="font-mono tabular-nums">{{ money(record.counted_cash) }}</span></template>
                        <template v-else-if="column.key === 'result'">
                            <span
                                v-if="shortOver(record.variance)"
                                class="font-mono tabular-nums"
                                :class="TONE_CLASSES[shortOver(record.variance).tone]"
                            >
                                {{ shortOver(record.variance).amount ? `${shortOver(record.variance).label} ${money(shortOver(record.variance).amount)}` : shortOver(record.variance).label }}
                            </span>
                            <span v-else class="text-gray-400">—</span>
                        </template>
                        <template v-else-if="column.key === 'status'">
                            <a-tag :color="SHIFT_STATUS[record.status]?.color" class="m-0">{{ SHIFT_STATUS[record.status]?.label ?? record.status }}</a-tag>
                            <div v-if="record.reopen_count" class="text-xs text-gray-500">Reopened {{ record.reopen_count }}×</div>
                            <div v-if="record.status === 'approved' && record.reviewed_by" class="text-xs text-gray-500">by {{ record.reviewed_by }}</div>
                            <div v-if="record.collected_at" class="text-xs text-teal-700" :data-testid="`count-collected-${record.id}`">
                                Collected {{ money(record.collected_amount) }} by {{ record.collected_by }} on {{ dayjs(record.collected_at).format("MMM D") }}
                            </div>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <div class="flex justify-end gap-2">
                                <IconTooltip name="View shift" hover="hover:bg-blue-500" :data-testid="`count-view-${record.id}`" @click="viewing = record">
                                    <IconEye size="18" class="mx-auto" />
                                </IconTooltip>
                                <ShiftReviewButtons :shift="record" :can-approve="canApprove" :current-user-id="currentUserId" />
                            </div>
                        </template>
                    </template>
                </a-table>
            </div>


        </template>

        <ShiftDetailPanel
            :shift="viewing"
            :can-approve="canApprove"
            :current-user-id="currentUserId"
            @close="viewing = null"
        />
    </WalletLayout>
</template>
