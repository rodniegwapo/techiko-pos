<script setup>
import { computed, ref } from "vue";
import dayjs from "dayjs";
import { IconLock, IconLockOpen } from "@tabler/icons-vue";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";
import WalletLayout from "./components/WalletLayout.vue";
import SetOpeningModal from "./components/drawer/SetOpeningModal.vue";
import CountCashModal from "./components/drawer/CountCashModal.vue";
import EndShiftModal from "./components/drawer/EndShiftModal.vue";
import MoneyByUserModal from "./components/drawer/MoneyByUserModal.vue";
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
    /** The user's own shift: open now (with figures), or the last one they ended today. */
    myShift: { type: Object, default: null },
    /** What the user's drawer still holds from their last shift today (null before their first). */
    nextShiftOpening: { type: Number, default: null },
    canStartShift: { type: Boolean, default: false },
    /** Cashiers still on shift here: the store's day ends after them. */
    openShiftsAtStore: { type: Array, default: () => [] },
    /** View details: each user's money by where it went. */
    canSeeMoneyByUser: { type: Boolean, default: false },
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
    () => canSetOpening.value || canCount.value || hasPermission("wallet-cash-ledger.store"),
);

const money = (v) => formattedTotal(Number(v) || 0);
const counted = computed(() => props.cashControl.counted_at != null);
const result = computed(() => (counted.value ? shortOver(props.cashControl.variance) : null));

const steps = computed(() => [
    { key: "opening", label: props.cashControl.opening_is_saved ? "Opening cash" : "Opening cash (not saved yet)", amount: props.cashControl.opening_cash, sign: "" },
    { key: "sales", label: "Cash sales", amount: props.cashControl.paid_cash_sales, sign: "+" },
    { key: "in", label: "Cash in", amount: props.cashControl.manual_in, sign: "+" },
    { key: "out", label: "Cash out", amount: props.cashControl.manual_out, sign: "−" },
]);

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
async function saveCount(amount, notes) {
    if (await actions.saveCount(amount, notes)) modal.value = null;
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
const stillOnShift = computed(() => props.openShiftsAtStore.map((s) => s.user).filter(Boolean).join(", "));
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
                <a-button v-if="canSeeMoneyByUser" size="small" data-testid="open-money-by-user" @click="modal = 'money'">
                    View details
                </a-button>
                <a-tag v-if="isClosed" color="orange" class="m-0 inline-flex items-center gap-1" data-testid="shift-closed">
                    <IconLock class="h-3 w-3" /> Day ended
                </a-tag>
                <a-tag v-else color="green" class="m-0" data-testid="shift-open">Day open</a-tag>
            </div>
        </div>

        <!-- My shift: the user's own drawer. Several cashiers can be on shift at once. -->
        <div v-if="isToday && (myShift || canStartShift)" class="mb-4 rounded-lg border border-teal-200 bg-teal-50/40 p-4" data-testid="my-shift">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-gray-800">My shift</span>
                    <a-tag v-if="myShift" :color="SHIFT_STATUS[myShift.status]?.color" class="m-0" data-testid="my-shift-status">
                        {{ SHIFT_STATUS[myShift.status]?.label ?? myShift.status }}
                    </a-tag>
                    <span v-if="myShift" class="text-xs text-gray-500">
                        Started {{ dayjs(myShift.opened_at).format("h:mm A") }}<template v-if="myShift.closed_at">, ended {{ dayjs(myShift.closed_at).format("h:mm A") }}</template>
                    </span>
                    <span v-else class="text-xs text-gray-500">Not started. Start it to take payments.</span>
                </div>
                <div class="flex flex-wrap gap-2">
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
            <a-button type="link" size="small" class="mt-2 h-auto !px-0" data-testid="shift-guide-toggle" @click="showGuide = !showGuide">
                {{ showGuide ? "Hide the steps" : "How a shift works" }}
            </a-button>
            <ol v-if="showGuide" class="mb-0 mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700" data-testid="shift-guide">
                <li><b>Start my shift</b> and count the cash you start with (the opening).</li>
                <li>Take payments. Cash sales add to your <b>Expected</b>; GCash, card and bank go to their channels, not your drawer.</li>
                <li>Paid something from the drawer, or added change? A manager records it under <b>Cash in &amp; out</b>, or add it as an expense when you end.</li>
                <li><b>End my shift</b>: count every bill and coin and enter the total. Don't count other people's cash.</li>
                <li>If your count is off from Expected, a manager reviews it on <b>Counted money</b>. Made a mistake? <b>Reopen</b> and say why.</li>
            </ol>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            <!-- How Expected is reached -->
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:col-span-2" data-testid="drawer-steps">
                <div class="mb-2 text-sm font-semibold text-gray-800">What should be in the drawer</div>
                <div v-for="step in steps" :key="step.key" class="flex justify-between border-b border-gray-100 py-2 text-sm" :data-testid="`step-${step.key}`">
                    <span class="text-gray-600">{{ step.label }}</span>
                    <span class="font-mono tabular-nums">{{ step.sign }} {{ money(step.amount) }}</span>
                </div>
                <div class="flex justify-between pt-2 text-sm font-semibold">
                    <span>= Expected</span>
                    <span class="font-mono tabular-nums">{{ money(cashControl.expected_cash) }}</span>
                </div>
                <p v-if="!cashControl.opening_is_saved && cashControl.suggestion_source_date" class="mb-0 mt-2 text-xs text-amber-700">
                    Opening suggested from the count on {{ cashControl.suggestion_source_date }}. Save it with Set opening.
                </p>
            </div>

            <!-- The three numbers -->
            <div class="lg:col-span-3">
                <div class="grid grid-cols-1 gap-3" :class="canSeeCountResult ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
                        <div class="text-xs font-medium uppercase text-gray-500">Expected</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-gray-900" data-testid="drawer-expected">
                            {{ money(cashControl.expected_cash) }}
                        </div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
                        <div class="text-xs font-medium uppercase text-gray-500">Last count</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-gray-900" data-testid="drawer-counted">
                            {{ counted ? money(cashControl.counted_cash) : "—" }}
                        </div>
                        <div class="text-xs text-gray-500" data-testid="drawer-counted-how">
                            <template v-if="counted">
                                {{ COUNT_KIND_LABELS[cashControl.counted_kind] ?? "Count" }} · {{ dayjs(cashControl.counted_at).format("h:mm A") }}<template v-if="cashControl.counted_by_user"> · {{ cashControl.counted_by_user.name }}</template>
                            </template>
                            <template v-else-if="isClosed">Day ended. Its counts are in Drawer history.</template>
                            <template v-else>Not counted yet. End the day counts it.</template>
                        </div>
                    </div>
                    <div v-if="canSeeCountResult" class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
                        <div class="text-xs font-medium uppercase text-gray-500">Short / Over</div>
                        <div
                            class="mt-1 font-mono text-2xl font-bold tabular-nums"
                            :class="result ? TONE_CLASSES[result.tone] : 'text-gray-400'"
                            data-testid="drawer-result"
                        >
                            <template v-if="result">{{ result.amount ? `${result.label} ${money(result.amount)}` : result.label }}</template>
                            <template v-else>Not counted</template>
                        </div>
                    </div>
                </div>

                <div v-if="canManage" class="mt-4 flex flex-wrap gap-2">
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
                        <!-- Optional: a count during the day to catch a mistake early. -->
                        <a-button v-if="canCount" data-testid="open-count-cash" @click="modal = 'count'">Spot check</a-button>
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
                <p v-if="!isClosed && canCount && canSeeCountResult" class="mb-0 mt-2 text-xs text-gray-500" data-testid="spot-check-hint">
                    Spot check is optional: count any time to catch a mistake early. End the day includes the final count.
                </p>
                <p v-if="!isClosed && canSeeCountResult && stillOnShift" class="mb-0 mt-2 text-xs text-gray-600" data-testid="still-on-shift">
                    Still on shift: {{ stillOnShift }}. The day ends after each of them ends their shift.
                </p>
                <p v-if="cashControl.last_reopen" class="mb-0 mt-3 text-xs text-gray-600" data-testid="last-reopen">
                    Reopened by {{ cashControl.last_reopen.by }} at {{ dayjs(cashControl.last_reopen.at).format("h:mm A") }}<template v-if="cashControl.last_reopen.reason">: {{ cashControl.last_reopen.reason }}</template>
                </p>
                <p v-if="canSeeCountResult" class="mb-0 mt-3 text-xs text-gray-500">
                    Counts, opening changes and past shifts are in
                    <a-button type="link" class="h-auto p-0 text-xs" @click="visit('wallet.history')">Drawer history</a-button>.
                </p>
            </div>
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
        <MoneyByUserModal
            v-if="canSeeMoneyByUser"
            :open="modal === 'money'"
            :location-id="locationId"
            :business-date="businessDate"
            @close="modal = null"
        />

        <SetOpeningModal
            :open="modal === 'opening'"
            :cash-control="cashControl"
            :saving="actions.busy.value === 'opening'"
            @close="modal = null"
            @save="saveOpening"
        />
        <CountCashModal
            :open="modal === 'count'"
            :cash-control="cashControl"
            :show-result="canSeeCountResult"
            :saving="actions.busy.value === 'count'"
            @close="modal = null"
            @save="saveCount"
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
