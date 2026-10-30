<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import dayjs from "dayjs";
import { IconLock, IconLockOpen } from "@tabler/icons-vue";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";
import WalletLayout from "./components/WalletLayout.vue";
import DrawerShiftsTable from "./components/shifts/DrawerShiftsTable.vue";
import ShiftDetailPanel from "./components/shifts/ShiftDetailPanel.vue";
import ShiftReviewButtons from "./components/shifts/ShiftReviewButtons.vue";
import DrawerSummaryCards from "./components/drawer/DrawerSummaryCards.vue";
import SetOpeningModal from "./components/drawer/SetOpeningModal.vue";
import CountCashModal from "./components/drawer/CountCashModal.vue";
import EndShiftModal from "./components/drawer/EndShiftModal.vue";
import { shortOver, TONE_CLASSES, SHIFT_STATUS, COUNT_KIND_LABELS } from "./components/walletLabels";
import { useWalletQuery } from "./composables/useWalletQuery";
import { useCashDrawerActions } from "./composables/useCashDrawerActions";

/**
 * Today's drawer: what should be in the cash drawer (opening + cash sales + cash in − cash out),
 * what was counted, and whether it is short or over. Start, count and end the shift here.
 */
const props = defineProps({
    cashControl: { type: Object, required: true },
    activeLocation: { type: Object, default: null },
    canViewAllMoney: { type: Boolean, default: false },
    /** Managers see whether a count came out short or over, and end the store's day. */
    canSeeCountResult: { type: Boolean, default: true },
    /** Supervisors and up see the store's whole drawer; a cashier, only their own shift (own tray). */
    canSeeStoreDrawer: { type: Boolean, default: true },
    /** The user's own takings today by where they went: [{ key, label, amount }]. */
    myTakings: { type: Array, default: null },
    /** Owners and admins: the business's cash they hold, every store's (Cash on hand): { balance, collected_today }. */
    cashOnHand: { type: Object, default: null },
    /** Spot check your own drawer during your shift. */
    canSpotCheck: { type: Boolean, default: false },
    /** Supervisors and up: every cashier's shift that day, adding up to the store's Expected. */
    drawerByShift: { type: Object, default: null },
    canApprove: { type: Boolean, default: false },
    currentUserId: { type: Number, default: null },
    /** The user's own shift: open now (with figures), or the last one they ended today. */
    myShift: { type: Object, default: null },
    /** What the user's drawer still holds from their last shift today (null before their first). */
    nextShiftOpening: { type: Number, default: null },
    canStartShift: { type: Boolean, default: false },
    /** Cashiers still on shift here: the store's day ends after them. */
    openShiftsAtStore: { type: Array, default: () => [] },
    /** Supervisors and up: how many ended shifts wait for review (on Shifts & counts). */
    needsReviewCount: { type: Number, default: 0 },
    /** Supervisors and up: the day at a glance (cash in drawers, cash sales, card/e-wallet/bank, counts off). */
    drawerSummary: { type: Object, default: null },
    /** Approvers: the counts waiting for review here, to approve or send back right on this page. */
    requiredActions: { type: Array, default: () => [] },
    /** Supervisors and up: shifts left open from an earlier day here (forgotten), with their Expected. */
    overdueShifts: { type: Array, default: () => [] },
    /** Managers and up: end a forgotten shift for the cashier, counting the tray. */
    canEndForOthers: { type: Boolean, default: false },
    /** Cash expenses can be added when ending the shift (empty without Expenses). */
    expenseCategories: { type: Array, default: () => [] },
});

const { hasPermission } = usePermissionsV2();
const { formattedTotal } = useHelpers();
const { locationId, visit, todayYmd } = useWalletQuery(() => props.activeLocation);

const businessDate = computed(() => props.cashControl.business_date);
const isToday = computed(() => businessDate.value === todayYmd);
const isClosed = computed(() => !!props.cashControl.is_closed);
const actions = useCashDrawerActions(locationId, businessDate);

const canSetOpening = computed(() => hasPermission("wallet-cash-ledger.opening-cash.store"));
const canCount = computed(() => hasPermission("wallet-cash-ledger.counted-cash.store"));
const canManage = computed(
    () => props.canSeeStoreDrawer && (canSetOpening.value || canCount.value || hasPermission("wallet-cash-ledger.store")),
);

const money = (v) => formattedTotal(Number(v) || 0);
const counted = computed(() => props.cashControl.counted_at != null);
const result = computed(() => (counted.value ? shortOver(props.cashControl.variance) : null));


function changeDate(value) {
    if (value) visit("wallet.drawer", { business_date: value === todayYmd ? undefined : value });
}
const isFuture = (current) => !!current && current.format("YYYY-MM-DD") > todayYmd;

const modal = ref(null);
// The cashier's guide: open until they've started a shift today.
const showGuide = ref(!props.myShift);
async function saveOpening(amount, reason) {
    if (await actions.saveOpening(amount, reason)) modal.value = null;
}

// Spot check: always your own drawer, against your shift's Expected (owners and admins too).
async function saveSpotCheck(amount, notes) {
    if (await actions.spotCheckShift(props.myShift.id, amount, notes)) modal.value = null;
}
async function endShift(payload) {
    if (await actions.endShift(payload)) modal.value = null;
}

// A reopen always says why: it is kept in the log with who and when.
const reopenReason = ref("");
const reasonOk = computed(() => reopenReason.value.trim().length >= 3);
async function reopen() {
    if (!reasonOk.value) return;
    const done = modal.value === "my-reopen"
        ? await actions.reopenMyShift(props.myShift.id, reopenReason.value.trim())
        : await actions.reopenShift(reopenReason.value.trim());
    if (done) {
        modal.value = null;
        reopenReason.value = "";
    }
}

// My shift: the user's own drawer, next to the store's day.
const myOpen = computed(() => props.myShift?.status === "open");
const myFigures = computed(() => props.myShift?.figures ?? null);
const myResult = computed(() => (props.myShift && !myOpen.value ? shortOver(props.myShift.variance) : null));
// My cards: what's in my drawer now, then where my other sales went today.
const myCards = computed(() => {
    if (!props.myTakings || !props.myShift) return [];
    const drawer = myOpen.value ? myFigures.value?.expected_cash : props.myShift.counted_cash;
    return [
        { key: "drawer", label: "My cash drawer", note: myOpen.value ? "Expected now" : "Counted", amount: drawer },
        ...props.myTakings
            .filter((p) => !p.key.startsWith("cash:"))
            .map((p) => ({ key: p.key, label: p.label, note: "My sales today", amount: p.amount })),
    ];
});
const startForm = ref({ opening: 0, notes: "" });
// A next shift starts with the drawer the last one left; a different amount is recorded as cash in or out.
function openStartMyShift() {
    startForm.value = { opening: props.nextShiftOpening ?? 0, notes: "" };
    modal.value = "my-start";
}
async function startMyShift() {
    if (await actions.startMyShift(startForm.value.opening, startForm.value.notes)) modal.value = null;
}
async function endMyShift(payload) {
    if (await actions.endMyShift(props.myShift.id, payload)) modal.value = null;
}
// A manager ends a forgotten shift for the cashier: they count the tray, it goes to review if off.
const endingFor = ref(null);
function openEndFor(shift) {
    endingFor.value = shift;
    modal.value = "end-for";
}
async function endForThem(payload) {
    if (await actions.endMyShift(endingFor.value.id, payload)) {
        modal.value = null;
        endingFor.value = null;
    }
}
const overdueDay = (shift) => dayjs(shift.business_date).format("MMM D");
// A shift in full (side panel), and the review panel the summary's "Review" scrolls to.
const viewingShift = ref(null);
const actionsPanel = ref(null);
function scrollToActions() {
    actionsPanel.value?.scrollIntoView({ behavior: "smooth", block: "start" });
}
const stillOnShift = computed(() => props.openShiftsAtStore.map((s) => s.user).filter(Boolean).join(", "));

// Others end, start and count shifts while this page is open (a cashier ending theirs on another
// device): re-check now and then, and when coming back to the tab. Not while a dialog is open or a
// visit is in progress (a reload would cancel it), nor for a past day.
const REFRESH_MS = 30000;
const LIVE_PROPS = ["cashControl", "myShift", "nextShiftOpening", "openShiftsAtStore", "drawerByShift", "myTakings", "cashOnHand", "drawerSummary", "requiredActions", "needsReviewCount", "overdueShifts"];
let refreshTimer = null;
let visitActive = false;
const stopStart = router.on("start", () => (visitActive = true));
const stopFinish = router.on("finish", () => (visitActive = false));
function refreshDrawer() {
    if (!isToday.value || modal.value || viewingShift.value || visitActive || document.visibilityState === "hidden") return;
    router.reload({ only: LIVE_PROPS });
}
function onTabVisible() {
    if (document.visibilityState === "visible") refreshDrawer();
}
onMounted(() => {
    document.addEventListener("visibilitychange", onTabVisible);
    refreshTimer = window.setInterval(refreshDrawer, REFRESH_MS);
});
onBeforeUnmount(() => {
    document.removeEventListener("visibilitychange", onTabVisible);
    window.clearInterval(refreshTimer);
    stopStart();
    stopFinish();
});
</script>

<template>
    <WalletLayout title="Today's drawer" active="drawer" :active-location="activeLocation">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <a-date-picker
                    :value="businessDate"
                    value-format="YYYY-MM-DD"
                    :allow-clear="false"
                    :disabled-date="isFuture"
                    data-testid="drawer-date"
                    @change="changeDate"
                />
                <span v-if="!isToday" class="text-sm text-gray-600">
                    Viewing {{ dayjs(businessDate).format("ddd, MMM D, YYYY") }} ·
                    <a-button type="link" class="h-auto p-0" @click="visit('wallet.drawer', { business_date: undefined })">Back to today</a-button>
                </span>
                <span v-if="activeLocation" class="text-sm text-gray-500">{{ activeLocation.name }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a-button
                    v-if="needsReviewCount > 0 && !requiredActions.length"
                    size="small"
                    class="!border-amber-300 !text-amber-700"
                    data-testid="needs-review-badge"
                    @click="visit('register-shifts.index', { status: 'needs_review' })"
                >
                    Needs review ({{ needsReviewCount }})
                </a-button>
                <a-tag v-if="isClosed" color="orange" class="m-0 inline-flex items-center gap-1" data-testid="shift-closed">
                    <IconLock class="h-3 w-3" /> Day ended
                </a-tag>
                <a-tag v-else color="green" class="m-0" data-testid="shift-open">Day open</a-tag>
            </div>
        </div>

        <!-- My shift: the user's own drawer. Several cashiers can be on shift at once. -->
        <a-alert
            v-if="isToday && myShift?.overdue"
            type="warning"
            show-icon
            class="mb-3"
            data-testid="my-shift-overdue"
        >
            <template #message>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>Your shift from {{ overdueDay(myShift) }} is still open. Count your drawer and end it before taking payments.</span>
                    <a-button size="small" type="primary" data-testid="overdue-end-my-shift" @click="modal = 'my-end'">End my shift</a-button>
                </div>
            </template>
        </a-alert>
        <div v-if="isToday && (myShift || canStartShift || cashOnHand)" class="mb-4 rounded-lg border border-teal-200 bg-teal-50/40 p-4" data-testid="my-shift">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-gray-800">My shift</span>
                    <a-tag v-if="myShift" :color="SHIFT_STATUS[myShift.status]?.color" class="m-0" data-testid="my-shift-status">
                        {{ SHIFT_STATUS[myShift.status]?.label ?? myShift.status }}
                    </a-tag>
                    <span v-if="myShift" class="text-xs text-gray-500">
                        Started {{ dayjs(myShift.opened_at).format("h:mm A") }}<template v-if="myShift.closed_at">, ended {{ dayjs(myShift.closed_at).format("h:mm A") }}</template>
                    </span>
                    <span v-else-if="canStartShift" class="text-xs text-gray-500">Not started. Start it to take payments.</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a-button v-if="myOpen && canSpotCheck" data-testid="spot-check-my-drawer" @click="modal = 'spot'">Spot check my drawer</a-button>
                    <a-button v-if="myOpen" type="primary" data-testid="open-end-my-shift" @click="modal = 'my-end'">End my shift</a-button>
                    <a-button v-if="myShift?.can_reopen" data-testid="reopen-my-shift" @click="modal = 'my-reopen'">Reopen</a-button>
                    <a-button v-if="!myOpen && canStartShift && !isClosed" :type="myShift ? 'default' : 'primary'" data-testid="open-start-my-shift" @click="openStartMyShift">
                        {{ myShift ? "Start a new shift" : "Start my shift" }}
                    </a-button>
                </div>
            </div>
            <div v-if="myOpen && myFigures" class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5" data-testid="my-shift-figures">
                <div><div class="text-xs text-gray-500">Opening</div><div class="font-mono tabular-nums">{{ money(myFigures.opening_cash) }}</div></div>
                <div><div class="text-xs text-gray-500">Cash sales</div><div class="font-mono tabular-nums">+ {{ money(myFigures.paid_cash_sales) }}</div></div>
                <div><div class="text-xs text-gray-500">Cash in</div><div class="font-mono tabular-nums">+ {{ money(myFigures.manual_in) }}</div></div>
                <div><div class="text-xs text-gray-500">Cash out</div><div class="font-mono tabular-nums">− {{ money(myFigures.manual_out) }}</div></div>
                <div><div class="text-xs text-gray-500">Expected</div><div class="font-mono font-semibold tabular-nums" data-testid="my-shift-expected">{{ money(myFigures.expected_cash) }}</div></div>
            </div>
            <p v-else-if="myShift && !myOpen" class="mb-0 mt-2 text-sm text-gray-700">
                Counted {{ money(myShift.counted_cash) }}<template v-if="canSeeCountResult && myResult">
                    · <span :class="TONE_CLASSES[myResult.tone]">{{ myResult.amount ? `${myResult.label} ${money(myResult.amount)}` : myResult.label }}</span></template>.
                <template v-if="myShift.status === 'needs_review'"> The count is off from Expected, so a manager will review it. You can start a new shift meanwhile.</template>
                <template v-else-if="myShift.status === 'approved'"> Approved<template v-if="myShift.reviewed_by"> by {{ myShift.reviewed_by }}</template>.</template>
            </p>
            <div v-if="myCards.length || cashOnHand" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="my-takings">
                <div
                    v-for="card in myCards"
                    :key="card.key"
                    class="rounded-lg border border-gray-200 bg-white px-3 py-2 shadow-sm"
                    :data-testid="`my-takings-${card.key}`"
                >
                    <div class="truncate text-sm font-medium text-gray-700">{{ card.label }}</div>
                    <div class="text-xs text-gray-500">{{ card.note }}</div>
                    <div class="mt-1 font-mono text-lg font-bold tabular-nums text-gray-900">{{ money(card.amount) }}</div>
                </div>
                <!-- An owner's own money: the cash collected from the drawers, still the business's. -->
                <div v-if="cashOnHand" class="rounded-lg border border-teal-200 bg-white px-3 py-2 shadow-sm" data-testid="my-cash-on-hand">
                    <div class="flex items-start justify-between gap-2">
                        <div class="truncate text-sm font-medium text-gray-700">Cash on hand</div>
                        <a-button type="link" size="small" class="h-auto !px-0 text-xs" @click="visit('cash-on-hand.index')">Open</a-button>
                    </div>
                    <div class="text-xs text-gray-500">All stores: cash collected from every store's drawers</div>
                    <div class="mt-1 font-mono text-lg font-bold tabular-nums text-gray-900" data-testid="my-cash-on-hand-amount">{{ money(cashOnHand.balance) }}</div>
                    <div class="text-xs text-teal-700" data-testid="my-cash-on-hand-today">Collected today {{ money(cashOnHand.collected_today) }}</div>
                </div>
            </div>
            <p v-if="myOpen && myShift.last_spot_check" class="mb-0 mt-2 text-xs text-gray-600" data-testid="my-last-spot-check">
                Last spot check {{ money(myShift.last_spot_check.counted_cash) }} at {{ dayjs(myShift.last_spot_check.at).format("h:mm A") }}<template v-if="canSeeCountResult && shortOver(myShift.last_spot_check.variance)">
                    · <span :class="TONE_CLASSES[shortOver(myShift.last_spot_check.variance).tone]">{{ shortOver(myShift.last_spot_check.variance).amount ? `${shortOver(myShift.last_spot_check.variance).label} ${money(shortOver(myShift.last_spot_check.variance).amount)}` : shortOver(myShift.last_spot_check.variance).label }}</span></template>
            </p>
            <a-button v-if="myShift || canStartShift" type="link" size="small" class="mt-2 h-auto !px-0" data-testid="shift-guide-toggle" @click="showGuide = !showGuide">
                {{ showGuide ? "Hide the steps" : "How a shift works" }}
            </a-button>
            <ol v-if="showGuide && (myShift || canStartShift)" class="mb-0 mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700" data-testid="shift-guide">
                <li><b>Start my shift</b> and count the cash you start with (the opening).</li>
                <li>Take payments. Cash sales add to your <b>Expected</b>; GCash, card and bank go to their channels, not your drawer.</li>
                <li>Paid something from the drawer, or added change? A manager records it under <b>Cash in &amp; out</b>, or add it as an expense when you end.</li>
                <li><b>End my shift</b>: count every bill and coin and enter the total. Don't count other people's cash.</li>
                <li>If your count is off from Expected, a manager reviews it on <b>Shifts &amp; counts</b>. Made a mistake? <b>Reopen</b> and say why.</li>
            </ol>
        </div>

        <p v-if="!canSeeStoreDrawer && !isToday" class="text-sm text-gray-600" data-testid="drawer-own-shift-only">
            Your drawer is your own shift, shown here on the day. A manager has the counts of earlier days.
        </p>

        <div v-if="canSeeStoreDrawer" class="flex flex-col gap-4">
            <DrawerSummaryCards
                v-if="drawerSummary && isToday"
                :summary="drawerSummary"
                :on-shift="openShiftsAtStore.length"
                @review="scrollToActions"
            />

            <!-- The store's whole drawer: its last count, and ending the day; each shift is in the table below. -->
            <div>
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm" data-testid="store-drawer-line">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                        <span class="font-semibold text-gray-800">Store drawer</span>
                        <span class="text-gray-500">· Last count</span>
                        <span class="font-mono font-semibold tabular-nums text-gray-900" data-testid="drawer-counted">{{ counted ? money(cashControl.counted_cash) : "—" }}</span>
                        <span class="text-xs text-gray-500" data-testid="drawer-counted-how">
                            <template v-if="counted">
                                {{ COUNT_KIND_LABELS[cashControl.counted_kind] ?? "Count" }} · {{ dayjs(cashControl.counted_at).format("h:mm A") }}<template v-if="cashControl.counted_by_user"> · {{ cashControl.counted_by_user.name }}</template>
                            </template>
                            <template v-else-if="isClosed">Day ended. Its counts are in Shifts &amp; counts.</template>
                            <template v-else>Not counted yet. End the day counts it.</template>
                        </span>
                        <template v-if="canSeeCountResult">
                            <span class="text-gray-400">·</span>
                            <span
                                class="font-mono font-semibold tabular-nums"
                                :class="result ? TONE_CLASSES[result.tone] : 'text-gray-400'"
                                data-testid="drawer-result"
                            >
                                <template v-if="result">{{ result.amount ? `${result.label} ${money(result.amount)}` : result.label }}</template>
                                <template v-else>Not counted</template>
                            </span>
                        </template>
                    </div>
                    <div v-if="canManage" class="flex flex-wrap gap-2">
                        <template v-if="!isClosed">
                            <!-- The store's day is a manager's to end, after every cashier has ended their own shift. It takes the final count. -->
                            <a-button
                                v-if="canSeeCountResult"
                                type="primary"
                                class="inline-flex items-center gap-1"
                                :disabled="openShiftsAtStore.length > 0"
                                data-testid="open-end-shift"
                                @click="modal = 'end'"
                            >
                                <IconLock class="h-4 w-4" /> End the day
                            </a-button>
                            <a-button v-if="canSetOpening" data-testid="open-set-opening" @click="modal = 'opening'">Set opening</a-button>
                        </template>
                        <a-button
                            v-else-if="cashControl.can_reopen"
                            class="inline-flex items-center gap-1"
                            data-testid="reopen-shift"
                            @click="modal = 'reopen'"
                        >
                            <IconLockOpen class="h-4 w-4" /> Reopen the day
                        </a-button>
                        <span v-else class="text-xs text-gray-500">Ask a manager or admin to reopen the day.</span>
                    </div>
                </div>
                <p v-if="!isClosed && canSeeCountResult" class="mb-0 mt-2 text-xs text-gray-500" data-testid="spot-check-hint">
                    Each person spot checks their own drawer in My shift. End the day takes the store's final count.
                </p>
                <p v-if="!isClosed && canSeeCountResult && stillOnShift" class="mb-0 mt-2 text-xs text-gray-600" data-testid="still-on-shift">
                    Still on shift: {{ stillOnShift }}. The day ends after each of them ends their shift.
                </p>
                <p v-if="cashControl.last_reopen" class="mb-0 mt-3 text-xs text-gray-600" data-testid="last-reopen">
                    Reopened by {{ cashControl.last_reopen.by }} at {{ dayjs(cashControl.last_reopen.at).format("h:mm A") }}<template v-if="cashControl.last_reopen.reason">: {{ cashControl.last_reopen.reason }}</template>
                </p>
                <p v-if="canSeeCountResult" class="mb-0 mt-3 text-xs text-gray-500">
                    Counts, opening changes and past shifts are in
                    <a-button type="link" class="h-auto p-0 text-xs" @click="visit('register-shifts.index')">Shifts &amp; counts</a-button>.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4" :class="{ 'xl:grid-cols-4': requiredActions.length || overdueShifts.length }">
                <!-- How Expected is reached: each cashier's shift, and the whole store's line -->
                <DrawerShiftsTable
                    v-if="drawerByShift"
                    :class="{ 'xl:col-span-3': requiredActions.length || overdueShifts.length }"
                    :drawer-by-shift="drawerByShift"
                    :cash-control="cashControl"
                    :can-see-count-result="canSeeCountResult"
                    :can-approve="canApprove"
                    :current-user-id="currentUserId"
                    @view="(row) => (viewingShift = row)"
                />

                <div v-if="requiredActions.length || overdueShifts.length" class="flex flex-col gap-4">
                <!-- Shifts forgotten from an earlier day: count the tray and end them for the cashier -->
                <section v-if="overdueShifts.length" class="rounded-lg border border-amber-300 bg-white p-4 shadow-sm" data-testid="overdue-shifts">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-800">Forgotten shifts</span>
                        <a-tag color="orange" class="m-0">{{ overdueShifts.length }}</a-tag>
                    </div>
                    <div class="flex flex-col gap-3">
                        <div v-for="shift in overdueShifts" :key="shift.id" class="rounded-lg border border-amber-200 bg-amber-50/50 p-3" :data-testid="`overdue-shift-${shift.id}`">
                            <div class="flex items-start justify-between gap-2">
                                <a-button type="link" class="h-auto !px-0 text-sm font-medium" @click="viewingShift = shift">{{ shift.user?.name ?? "—" }}</a-button>
                                <a-tag color="orange" class="m-0">Overdue since {{ overdueDay(shift) }}</a-tag>
                            </div>
                            <div class="mt-1 text-xs text-gray-600">Still open. Expected in the tray: {{ money(shift.expected_cash) }}.</div>
                            <a-button
                                v-if="canEndForOthers"
                                size="small"
                                class="mt-2"
                                :data-testid="`end-for-them-${shift.id}`"
                                @click="openEndFor(shift)"
                            >
                                End for them
                            </a-button>
                            <div v-else class="mt-2 text-xs text-gray-500">Ask the cashier, or a manager, to end it.</div>
                        </div>
                    </div>
                </section>

                <!-- Counts waiting for a manager: approve or send back without leaving the page -->
                <section v-if="requiredActions.length" ref="actionsPanel" class="rounded-lg border border-red-200 bg-white p-4 shadow-sm" data-testid="required-actions">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-800">Needs your review</span>
                        <a-tag color="red" class="m-0">{{ requiredActions.length }}</a-tag>
                    </div>
                    <div class="flex flex-col gap-3">
                        <div
                            v-for="shift in requiredActions"
                            :key="shift.id"
                            class="rounded-lg border border-red-100 bg-red-50/40 p-3"
                            :data-testid="`required-action-${shift.id}`"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a-button type="link" class="h-auto !px-0 text-sm font-medium" @click="viewingShift = shift">{{ shift.user?.name ?? "—" }}</a-button>
                                    <div class="text-xs text-gray-500">{{ dayjs(shift.business_date).format("MMM D") }} · ended {{ shift.closed_at ? dayjs(shift.closed_at).format("h:mm A") : "" }}</div>
                                </div>
                                <span v-if="shortOver(shift.variance)" class="shrink-0 font-mono text-sm font-semibold tabular-nums" :class="TONE_CLASSES[shortOver(shift.variance).tone]">
                                    {{ shortOver(shift.variance).label }} {{ money(shortOver(shift.variance).amount) }}
                                </span>
                            </div>
                            <div class="mt-1 text-xs text-gray-600">Counted {{ money(shift.counted_cash) }} of {{ money(shift.expected_cash) }} expected.</div>
                            <div class="mt-2">
                                <ShiftReviewButtons :shift="shift" :can-approve="canApprove" :current-user-id="currentUserId" />
                            </div>
                        </div>
                    </div>
                </section>
                </div>
            </div>

            <ShiftDetailPanel
                :shift="viewingShift"
                :can-approve="canApprove"
                :show-result="canSeeCountResult"
                :current-user-id="currentUserId"
                @close="viewingShift = null"
            />
        </div>

        <a-modal
            :visible="modal === 'reopen' || modal === 'my-reopen'"
            :title="modal === 'my-reopen' ? 'Reopen my shift' : 'Reopen the day'"
            ok-text="Reopen"
            :ok-button-props="{ 'data-testid': 'reopen-confirm', disabled: !reasonOk }"
            :confirm-loading="actions.busy.value === 'reopen' || actions.busy.value === 'my-reopen'"
            @ok="reopen"
            @cancel="modal = null"
        >
            <p class="text-sm text-gray-600">
                <template v-if="modal === 'my-reopen'">
                    Your count stays in the history; ending the shift again adds a new count.
                </template>
                <template v-else>
                    <template v-if="cashControl.closed_by_name">{{ cashControl.closed_by_name }} ended the day. </template>
                    Reopening lets payments, counts and changes happen again.
                </template>
                Your name, the time and the reason are kept in the log.
            </p>
            <a-input
                v-model:value="reopenReason"
                placeholder="Reason, e.g. counted wrong"
                :maxlength="255"
                data-testid="reopen-reason"
                @press-enter="reopen"
            />
        </a-modal>

        <a-modal
            :visible="modal === 'my-start'"
            title="Start my shift"
            ok-text="Start shift"
            :ok-button-props="{ 'data-testid': 'confirm-start-my-shift' }"
            :confirm-loading="actions.busy.value === 'my-start'"
            @ok="startMyShift"
            @cancel="modal = null"
        >
            <p class="text-sm text-gray-600">Count the cash you start with. Your sales and cash lines count toward your own drawer.</p>
            <label class="mb-1 block text-sm text-gray-700">Opening cash</label>
            <a-input-number v-model:value="startForm.opening" :min="0" :precision="2" class="mb-3 w-full" addon-before="₱" data-testid="my-shift-opening" />
            <p v-if="nextShiftOpening !== null" class="-mt-2 mb-3 text-xs text-gray-500" data-testid="my-shift-carried">
                Your drawer should have {{ money(nextShiftOpening) }} from your last shift. If you added or took out cash, enter what's in it now; the difference is recorded in Cash in &amp; out.
            </p>
            <a-input v-model:value="startForm.notes" placeholder="Note (optional)" :maxlength="255" />
        </a-modal>

        <EndShiftModal
            v-if="myOpen"
            :open="modal === 'my-end'"
            :cash-control="{ expected_cash: myFigures?.expected_cash ?? 0, counted_at: null }"
            :saving="actions.busy.value === 'my-end'"
            :expense-categories="expenseCategories"
            :show-expected="canSeeCountResult"
            own-shift
            @close="modal = null"
            @end="endMyShift"
        />

        <EndShiftModal
            :open="modal === 'end-for'"
            :cash-control="{ expected_cash: endingFor?.expected_cash ?? 0, counted_at: null }"
            :saving="actions.busy.value === 'my-end'"
            :expense-categories="expenseCategories"
            :show-expected="canSeeCountResult"
            :for-name="endingFor?.user?.name ?? 'the cashier'"
            own-shift
            @close="modal = null"
            @end="endForThem"
        />

        <SetOpeningModal
            :open="modal === 'opening'"
            :cash-control="cashControl"
            :saving="actions.busy.value === 'opening'"
            @close="modal = null"
            @save="saveOpening"
        />
        <CountCashModal
            :open="modal === 'spot'"
            :expected="Number(myFigures?.expected_cash ?? 0)"
            :show-result="canSeeCountResult"
            :saving="actions.busy.value === 'spot'"
            @close="modal = null"
            @save="saveSpotCheck"
        />
        <EndShiftModal
            :open="modal === 'end'"
            :cash-control="cashControl"
            :saving="actions.busy.value === 'end'"
            :expense-categories="expenseCategories"
            :show-expected="canSeeCountResult"
            @close="modal = null"
            @end="endShift"
        />
    </WalletLayout>
</template>
