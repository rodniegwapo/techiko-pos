<script setup>
import { computed, reactive, ref, watch } from "vue";
import dayjs from "dayjs";
import { IconPlus, IconTrash } from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import ClockAlongField from "@/Pages/Sales/components/ClockAlongField.vue";
import BillCounter from "./BillCounter.vue";

/**
 * End a shift in one step: count the cash and add any expenses paid from the drawer during it.
 * No manager's PIN: a count that is off goes to a manager on Shifts & counts. Ending the store's
 * day also says where the cash goes: taken out, or left in the drawer as the next opening.
 * In pesos the cash can be counted bill by bill (BillCounter); only the total is saved. A spot
 * check earlier in the day fills the count in. A reopened shift's expenses (recordedExpenses) are
 * already in the list: they can be changed or removed, and aren't added a second time.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** expected_cash, and counted_cash / counted_at / counted_kind / notes when a count was already saved. */
    cashControl: { type: Object, required: true },
    saving: { type: Boolean, default: false },
    /** Empty when the plan has no Expenses. */
    expenseCategories: { type: Array, default: () => [] },
    /** Expenses already on this shift (it was ended and reopened): { id, expense_category_id, amount, description }. */
    recordedExpenses: { type: Array, default: null },
    /** Managers see Expected next to the count. */
    showExpected: { type: Boolean, default: true },
    /** The cashier's own shift: no "where the cash goes" choice, that is the store's day. */
    ownShift: { type: Boolean, default: false },
    /** Ending someone else's shift (a manager, for a forgotten one): their name for the title. */
    forName: { type: String, default: null },
    /** Your own drawer only: your time clock, to offer "Clock me out too". */
    clock: { type: Object, default: null },
    clockError: { type: String, default: "" },
});
const emit = defineEmits(["close", "end"]);
const { formattedTotal } = useHelpers();

const form = reactive({ counted: null, notes: "", expenses: [], action: "cashout_now" });

// The store's day after a spot check: the count starts from it.
const fromSpotCheck = computed(() => !props.ownShift && props.cashControl.counted_at != null && props.cashControl.counted_kind === "spot_check");

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const c = props.cashControl;
        Object.assign(form, {
            counted: c.counted_at != null ? Number(c.counted_cash) : null,
            notes: c.notes || "",
            expenses: (props.recordedExpenses ?? []).map((e) => ({ ...e })),
            action: "cashout_now",
        });
    },
    { immediate: true },
);

const countedAmount = computed(() => Number(form.counted || 0));
const expenseTotal = computed(() => form.expenses.reduce((sum, e) => sum + Number(e.amount || 0), 0));
// Expected already has the recorded expenses taken out; the hint takes out the whole list below instead.
const recordedTotal = computed(() => (props.recordedExpenses ?? []).reduce((sum, e) => sum + Number(e.amount || 0), 0));
const expectedBeforeExpenses = computed(() => Number(props.cashControl.expected_cash || 0) + recordedTotal.value);
const missingCount = computed(() => form.counted === null || form.counted === "" || !Number.isFinite(Number(form.counted)));

// Taking out nothing makes no sense: with an empty drawer the shift just closes with it as the opening.
watch(countedAmount, (amount) => {
    if (amount <= 0 && form.action === "cashout_now") form.action = "save_as_opening_cash";
});

// "Clock me out too": only on your own drawer, never when a manager closes someone else's.
const offerClockOut = computed(() => props.ownShift && !props.forName && !!props.clock);
const clockOut = ref({ enabled: false, pin: "" });
const clockPinMissing = computed(() => offerClockOut.value && clockOut.value.enabled && !clockOut.value.pin);
// A wrong PIN clears it so it's typed again.
watch(() => props.clockError, (err) => {
    if (err) clockOut.value = { ...clockOut.value, pin: "" };
});

const addExpense = () => form.expenses.push({ expense_category_id: null, amount: null, description: "" });

function submit() {
    emit("end", {
        counted_cash: Number(form.counted),
        notes: form.notes || null,
        ...(props.ownShift ? {} : { end_shift_action: form.action }),
        ...(props.recordedExpenses ? { replace_recorded_expenses: true } : {}),
        expenses: form.expenses
            .filter((e) => Number(e.amount) > 0)
            .map((e) => ({ ...(e.id ? { id: e.id } : {}), expense_category_id: e.expense_category_id, amount: Number(e.amount), description: e.description })),
        ...(offerClockOut.value && clockOut.value.enabled && clockOut.value.pin ? { clock_out_pin: clockOut.value.pin } : {}),
    });
}
</script>

<template>
    <a-modal :visible="open" :title="forName ? `Close ${forName}'s drawer` : ownShift ? 'Close my drawer' : 'End the store\'s day'" :width="560" destroy-on-close @cancel="emit('close')">
        <div class="flex flex-col gap-4 pt-1">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ ownShift ? "Cash in your drawer" : "Final count" }}</label>
                <p v-if="fromSpotCheck" class="mb-2 rounded bg-teal-50 px-2 py-1 text-xs text-teal-800" data-testid="end-shift-from-spot-check">
                    Filled in from your spot check at {{ dayjs(cashControl.counted_at).format("h:mm A") }}
                    ({{ formattedTotal(Number(cashControl.counted_cash) || 0) }}). Count again if cash came in or went out since.
                </p>
                <BillCounter v-model="form.counted" testid="end-shift-counted" prefix="end-shift" />
                <p v-if="showExpected" class="mb-0 mt-1 text-xs text-gray-500">
                    Expected: {{ formattedTotal(expectedBeforeExpenses) }}<template v-if="expenseTotal">
                        , less {{ formattedTotal(expenseTotal) }} in expenses below</template>.
                </p>
                <p v-if="ownShift" class="mb-0 mt-1 text-xs text-gray-500">
                    If the count is off, a manager reviews it. You can keep working meanwhile.
                </p>
            </div>

            <div v-if="expenseCategories.length" data-testid="end-shift-expenses">
                <div class="mb-1 flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700">Cash expenses this shift (optional)</span>
                    <a-button size="small" type="link" class="!flex items-center gap-1 !px-0" data-testid="end-shift-add-expense" @click="addExpense">
                        <IconPlus :size="14" /> Add expense
                    </a-button>
                </div>
                <p v-if="!form.expenses.length" class="m-0 text-xs text-gray-500">
                    Paid something from the drawer (ice, delivery, a small repair)? Add it so the drawer adds up.
                </p>
                <p v-if="recordedExpenses?.length" class="mb-1 text-xs text-gray-500" data-testid="end-shift-recorded-expenses">
                    Already recorded on this shift. Change or remove them here; don't add them again.
                </p>
                <div v-for="(e, i) in form.expenses" :key="e.id ?? `new-${i}`" class="mb-2 grid grid-cols-12 gap-2" data-testid="end-shift-expense">
                    <a-select
                        v-model:value="e.expense_category_id"
                        class="col-span-12 sm:col-span-4"
                        placeholder="For"
                        :options="expenseCategories.map((c) => ({ value: c.id, label: c.name }))"
                    />
                    <a-input v-model:value="e.description" class="col-span-7 sm:col-span-4" placeholder="What" :maxlength="255" />
                    <a-input-number v-model:value="e.amount" :min="0.01" :precision="2" class="col-span-4 sm:col-span-3 !w-full" placeholder="₱" />
                    <a-button type="text" danger class="col-span-1" aria-label="Remove expense" @click="form.expenses.splice(i, 1)">
                        <IconTrash :size="16" />
                    </a-button>
                </div>
            </div>

            <a-radio-group v-if="!ownShift" v-model:value="form.action" class="flex flex-col gap-2">
                <a-radio value="cashout_now" :disabled="countedAmount <= 0" data-testid="end-shift-cashout">
                    Cash out to Cash on hand
                    <div class="text-xs text-gray-500">The owner takes it (still the business's money); the drawer starts the next shift empty.</div>
                </a-radio>
                <a-radio value="save_as_opening_cash" data-testid="end-shift-keep">
                    Leave it as the next shift's opening
                    <div class="text-xs text-gray-500">The cash stays in the drawer.</div>
                </a-radio>
            </a-radio-group>

            <a-textarea v-model:value="form.notes" :rows="2" placeholder="Notes (optional)" />

            <ClockAlongField v-if="offerClockOut" v-model="clockOut" mode="out" :status="clock" :error="clockError" />
        </div>
        <template #footer>
            <a-button @click="emit('close')">Cancel</a-button>
            <a-button
                type="primary"
                :loading="saving"
                :disabled="missingCount || clockPinMissing"
                data-testid="confirm-end-shift"
                @click="submit"
            >
                {{ ownShift ? "Close my drawer" : "End the day" }}
            </a-button>
        </template>
    </a-modal>
</template>
