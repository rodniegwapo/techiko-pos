<script setup>
import { computed, ref } from "vue";
import axios from "axios";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";
import { router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import WalletLayout from "./components/WalletLayout.vue";
import CollectShiftModal from "./components/cash/CollectShiftModal.vue";
import { shortOver, TONE_CLASSES, SHIFT_STATUS, movementLabel } from "./components/walletLabels";
import { useWalletQuery } from "./composables/useWalletQuery";
import { firstValidationMessage } from "./composables/useCashDrawerActions";

/**
 * Counted money: each cashier's ended shift with its count and how far it was from Expected. The
 * ones that came out short or over wait here for a manager, who looks into them and approves.
 * Once approved, the owner collects the shift's cash into Cash on hand.
 */
const props = defineProps({
    status: { type: String, default: "needs_review" },
    shifts: { type: Object, required: true },
    statusCounts: { type: Object, default: () => ({}) },
    canApprove: { type: Boolean, default: false },
    /** Owners and admins: collect approved shifts' cash into Cash on hand. */
    canCollect: { type: Boolean, default: false },
    currentUserId: { type: Number, default: null },
    activeLocation: { type: Object, default: null },
});

const { getRoute } = useDomainRoutes();
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

const columns = [
    { title: "Date", key: "date" },
    { title: "Cashier", key: "cashier" },
    { title: "Time", key: "time" },
    { title: "Opening", key: "opening", align: "right" },
    { title: "Expected", key: "expected", align: "right" },
    { title: "Counted", key: "counted", align: "right" },
    { title: "Short / Over", key: "result", align: "right" },
    { title: "Status", key: "status" },
    { title: "", key: "actions", align: "right" },
];

const pagination = computed(() => ({
    current: props.shifts.current_page,
    pageSize: props.shifts.per_page,
    total: props.shifts.total,
    showSizeChanger: false,
}));
function changePage(p) {
    visit("register-shifts.index", { status: props.status, page: p.current });
}

const canApproveRow = (row) =>
    props.canApprove && ["closed", "needs_review"].includes(row.status);

// Approve, with an optional note of what was found.
const approving = ref(null);
const approveNote = ref("");
const approveBusy = ref(false);
function openApprove(row) {
    approving.value = row;
    approveNote.value = "";
}
function approve() {
    approveBusy.value = true;
    router.post(
        getRoute("register-shifts.approve", { registerShift: approving.value.id }),
        { note: approveNote.value.trim() || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                notification.success({ message: "Count approved." });
                approving.value = null;
            },
            onError: (errors) => notification.error({ message: Object.values(errors || {})[0] || "Could not approve." }),
            onFinish: () => (approveBusy.value = false),
        },
    );
}

// The count looks wrong: reopen the shift so the cashier counts again. Only today's, before approval
// (the server checks the rest: a shift the cashier has open now, an ended store day).
const canReopenRow = (row) =>
    canApproveRow(row) && dayjs(row.business_date).isSame(dayjs(), "day");
const reopening = ref(null);
const reopenReason = ref("");
const reopenBusy = ref(false);
const reopenReasonOk = computed(() => reopenReason.value.trim().length >= 3);
function openReopen(row) {
    reopening.value = row;
    reopenReason.value = "";
}
function reopen() {
    if (!reopenReasonOk.value) return;
    reopenBusy.value = true;
    router.post(
        getRoute("register-shifts.reopen", { registerShift: reopening.value.id }),
        { reason: reopenReason.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => {
                notification.success({ message: "Shift reopened for a recount." });
                reopening.value = null;
            },
            onError: (errors) => notification.error({ message: Object.values(errors || {})[0] || "Could not reopen the shift." }),
            onFinish: () => (reopenBusy.value = false),
        },
    );
}

const collecting = ref(null);

// View: the shift's sales, cash lines, every count and every reopen.
const detail = ref(null);
const detailLoading = ref(false);
async function openDetail(row) {
    detail.value = { shift: row };
    detailLoading.value = true;
    try {
        const { data } = await axios.get(getRoute("register-shifts.show", { registerShift: row.id }));
        detail.value = data;
    } catch (e) {
        notification.error({ message: firstValidationMessage(e) || "Could not load the shift." });
        detail.value = null;
    } finally {
        detailLoading.value = false;
    }
}
</script>

<template>
    <WalletLayout title="Counted money" active="counts" :active-location="activeLocation">
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
                            <a-button size="small" :data-testid="`count-view-${record.id}`" @click="openDetail(record)">View</a-button>
                            <a-button
                                v-if="canApproveRow(record)"
                                size="small"
                                type="primary"
                                :data-testid="`count-approve-${record.id}`"
                                @click="openApprove(record)"
                            >
                                Approve
                            </a-button>
                            <a-button
                                v-if="canReopenRow(record)"
                                size="small"
                                :data-testid="`count-reopen-${record.id}`"
                                @click="openReopen(record)"
                            >
                                Recount
                            </a-button>
                            <a-button
                                v-if="canCollect && record.collectable"
                                size="small"
                                type="primary"
                                :data-testid="`count-collect-${record.id}`"
                                @click="collecting = record"
                            >
                                Collect
                            </a-button>
                        </div>
                    </template>
                </template>
            </a-table>
        </div>

        <a-modal
            :visible="!!approving"
            title="Approve this count"
            ok-text="Approve"
            :ok-button-props="{ 'data-testid': 'approve-confirm' }"
            :confirm-loading="approveBusy"
            @ok="approve"
            @cancel="approving = null"
        >
            <template v-if="approving">
                <p class="text-sm text-gray-600">
                    {{ approving.user?.name }}'s shift on {{ dayjs(approving.business_date).format("MMM D") }}: counted
                    {{ money(approving.counted_cash) }} against {{ money(approving.expected_cash) }} expected.
                </p>
                <p v-if="approving.user?.id === currentUserId" class="text-xs text-amber-700">This is your own shift.</p>
                <a-input v-model:value="approveNote" placeholder="What you found (optional)" :maxlength="255" data-testid="approve-note" />
            </template>
        </a-modal>

        <a-modal
            :visible="!!reopening"
            title="Reopen for a recount"
            ok-text="Reopen shift"
            :ok-button-props="{ disabled: !reopenReasonOk, 'data-testid': 'reopen-confirm' }"
            :confirm-loading="reopenBusy"
            @ok="reopen"
            @cancel="reopening = null"
        >
            <template v-if="reopening">
                <p class="text-sm text-gray-600">
                    {{ reopening.user?.name }}'s shift opens again so they can count the drawer and end it once more.
                    This count ({{ money(reopening.counted_cash) }} against {{ money(reopening.expected_cash) }} expected)
                    stays in the shift's history.
                </p>
                <a-input
                    v-model:value="reopenReason"
                    placeholder="Reason, e.g. counted wrong"
                    :maxlength="255"
                    data-testid="reopen-reason"
                    @press-enter="reopen"
                />
            </template>
        </a-modal>

        <CollectShiftModal :shift="collecting" @close="collecting = null" />

        <a-modal :visible="!!detail" :title="detail?.shift?.user ? `${detail.shift.user.name}'s shift` : 'Shift'" :width="760" :footer="null" @cancel="detail = null">
            <a-spin :spinning="detailLoading">
                <div v-if="detail?.figures" class="flex flex-col gap-4" data-testid="count-detail">
                    <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                        <div><div class="text-xs text-gray-500">Opening</div><div class="font-mono tabular-nums">{{ money(detail.figures.opening_cash) }}</div></div>
                        <div><div class="text-xs text-gray-500">Cash sales</div><div class="font-mono tabular-nums">+ {{ money(detail.figures.paid_cash_sales) }}</div></div>
                        <div><div class="text-xs text-gray-500">Cash in</div><div class="font-mono tabular-nums">+ {{ money(detail.figures.manual_in) }}</div></div>
                        <div><div class="text-xs text-gray-500">Cash out</div><div class="font-mono tabular-nums">− {{ money(detail.figures.manual_out) }}</div></div>
                        <div><div class="text-xs text-gray-500">Expected</div><div class="font-mono font-semibold tabular-nums">{{ money(detail.figures.expected_cash) }}</div></div>
                    </div>

                    <div>
                        <div class="mb-1 text-sm font-semibold text-gray-800">Counts</div>
                        <p v-if="!detail.counts.length" class="m-0 text-xs text-gray-500">Not counted yet.</p>
                        <div v-for="c in detail.counts" :key="c.id" class="flex flex-wrap justify-between gap-2 border-b border-gray-100 py-1 text-sm">
                            <span>{{ dayjs(c.counted_at).format("MMM D, h:mm A") }} · {{ c.counted_by }}<template v-if="c.notes"> · {{ c.notes }}</template></span>
                            <span class="font-mono tabular-nums">
                                {{ money(c.counted_cash) }} / {{ money(c.expected_cash) }}
                                <span v-if="shortOver(c.variance)" :class="TONE_CLASSES[shortOver(c.variance).tone]">({{ shortOver(c.variance).label }}<template v-if="shortOver(c.variance).amount"> {{ money(shortOver(c.variance).amount) }}</template>)</span>
                                <a-tag v-if="c.approved_at" class="ml-1">Approved</a-tag>
                            </span>
                        </div>
                    </div>

                    <div v-if="detail.reopens.length">
                        <div class="mb-1 text-sm font-semibold text-gray-800">Reopened</div>
                        <div v-for="r in detail.reopens" :key="r.id" class="border-b border-gray-100 py-1 text-sm">
                            {{ dayjs(r.at).format("MMM D, h:mm A") }} · {{ r.by }}: {{ r.reason }}
                            <span v-if="r.before" class="text-xs text-gray-500">
                                (was {{ SHIFT_STATUS[r.before.status]?.label ?? r.before.status }}, counted {{ money(r.before.counted_cash) }})
                            </span>
                        </div>
                    </div>

                    <div>
                        <div class="mb-1 text-sm font-semibold text-gray-800">Cash in & out</div>
                        <p v-if="!detail.cash_lines.length" class="m-0 text-xs text-gray-500">None.</p>
                        <div v-for="l in detail.cash_lines" :key="l.id" class="flex justify-between border-b border-gray-100 py-1 text-sm">
                            <span>{{ time(l.created_at) }} · {{ movementLabel(l) }}<template v-if="l.notes"> · {{ l.notes }}</template></span>
                            <span class="font-mono tabular-nums">{{ l.direction === "in" ? "+" : "−" }} {{ money(l.amount) }}</span>
                        </div>
                    </div>

                    <div>
                        <div class="mb-1 text-sm font-semibold text-gray-800">Sales ({{ detail.sales.length }})</div>
                        <p v-if="!detail.sales.length" class="m-0 text-xs text-gray-500">None.</p>
                        <div class="max-h-60 overflow-y-auto">
                            <div v-for="s in detail.sales" :key="s.id" class="flex justify-between border-b border-gray-100 py-1 text-sm">
                                <span>{{ time(s.at) }} · {{ s.invoice_number }} · {{ s.channel || s.method }}<template v-if="s.status !== 'paid'"> · {{ s.status }}</template></span>
                                <span class="font-mono tabular-nums">{{ money(s.total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </a-spin>
        </a-modal>
    </WalletLayout>
</template>
