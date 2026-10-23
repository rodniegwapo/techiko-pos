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
import { shortOver, TONE_CLASSES } from "./components/walletLabels";
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
    /** Managers see whether a count came out short or over; a cashier sees what should be there. */
    canSeeCountResult: { type: Boolean, default: true },
    /** A cashier's cash is approved with a manager's PIN when ending the shift. */
    needsManagerPin: { type: Boolean, default: false },
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
async function saveOpening(amount, reason) {
    if (await actions.saveOpening(amount, reason)) modal.value = null;
}
async function saveCount(amount, notes) {
    if (await actions.saveCount(amount, notes)) modal.value = null;
}
async function endShift(payload) {
    if (await actions.endShift(payload)) modal.value = null;
}
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
            <a-tag v-if="isClosed" color="orange" class="m-0 inline-flex items-center gap-1" data-testid="shift-closed">
                <IconLock class="h-3 w-3" /> Shift ended
            </a-tag>
            <a-tag v-else color="green" class="m-0" data-testid="shift-open">Shift open</a-tag>
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
                        <div class="text-xs font-medium uppercase text-gray-500">Counted</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-gray-900" data-testid="drawer-counted">
                            {{ counted ? money(cashControl.counted_cash) : "—" }}
                        </div>
                        <div v-if="cashControl.counted_by_user" class="text-xs text-gray-500">by {{ cashControl.counted_by_user.name }}</div>
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
                        <a-button v-if="canSetOpening" data-testid="open-set-opening" @click="modal = 'opening'">Set opening</a-button>
                        <a-button v-if="canCount" data-testid="open-count-cash" @click="modal = 'count'">Count cash</a-button>
                        <a-button type="primary" class="inline-flex items-center gap-1" data-testid="open-end-shift" @click="modal = 'end'">
                            <IconLock class="h-4 w-4" /> End shift
                        </a-button>
                    </template>
                    <a-button
                        v-else-if="cashControl.can_reopen"
                        class="inline-flex items-center gap-1"
                        :loading="actions.busy.value === 'reopen'"
                        data-testid="reopen-shift"
                        @click="actions.reopenShift()"
                    >
                        <IconLockOpen class="h-4 w-4" /> Reopen shift
                    </a-button>
                    <span v-else class="text-xs text-gray-500">Only the person who ended this shift can reopen it.</span>
                </div>
                <p v-if="canSeeCountResult" class="mb-0 mt-3 text-xs text-gray-500">
                    Counts, opening changes and past shifts are in
                    <a-button type="link" class="h-auto p-0 text-xs" @click="visit('wallet.history')">Drawer history</a-button>.
                </p>
            </div>
        </div>

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
            :needs-manager-pin="needsManagerPin"
            :expense-categories="expenseCategories"
            :show-expected="canSeeCountResult"
            @close="modal = null"
            @end="endShift"
        />
    </WalletLayout>
</template>
