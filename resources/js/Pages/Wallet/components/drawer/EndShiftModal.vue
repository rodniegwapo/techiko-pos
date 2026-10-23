<script setup>
import { computed, reactive, watch } from "vue";
import { IconPlus, IconTrash } from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * End the shift in one step: count the cash, add any expenses paid from the drawer during the
 * shift, and (for a cashier) a manager's PIN. Then the counted cash is taken out, or left in the
 * drawer as the next shift's opening.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    cashControl: { type: Object, required: true },
    saving: { type: Boolean, default: false },
    /** Cashiers: a manager approves the cash with their PIN. */
    needsManagerPin: { type: Boolean, default: false },
    /** Empty when the plan has no Expenses. */
    expenseCategories: { type: Array, default: () => [] },
    /** Managers see Expected next to the count. */
    showExpected: { type: Boolean, default: true },
});
const emit = defineEmits(["close", "end"]);
const { formattedTotal } = useHelpers();

const form = reactive({ counted: null, notes: "", expenses: [], pin: "", action: "cashout_now" });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const c = props.cashControl;
        Object.assign(form, {
            counted: c.counted_at != null ? Number(c.counted_cash) : null,
            notes: c.notes || "",
            expenses: [],
            pin: "",
            action: "cashout_now",
        });
    },
    { immediate: true },
);

const countedAmount = computed(() => Number(form.counted || 0));
const expenseTotal = computed(() => form.expenses.reduce((sum, e) => sum + Number(e.amount || 0), 0));
const missingCount = computed(() => form.counted === null || form.counted === "" || !Number.isFinite(Number(form.counted)));

// Taking out nothing makes no sense: with an empty drawer the shift just closes with it as the opening.
watch(countedAmount, (amount) => {
    if (amount <= 0 && form.action === "cashout_now") form.action = "save_as_opening_cash";
});

const addExpense = () => form.expenses.push({ expense_category_id: null, amount: null, description: "" });

function submit() {
    emit("end", {
        counted_cash: Number(form.counted),
        notes: form.notes || null,
        end_shift_action: form.action,
        expenses: form.expenses
            .filter((e) => Number(e.amount) > 0)
            .map((e) => ({ expense_category_id: e.expense_category_id, amount: Number(e.amount), description: e.description })),
        pin_code: props.needsManagerPin ? form.pin : undefined,
    });
}
</script>

<template>
    <a-modal :visible="open" title="End shift" :width="560" destroy-on-close @cancel="emit('close')">
        <div class="flex flex-col gap-4 pt-1">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Cash in the drawer</label>
                <a-input-number
                    v-model:value="form.counted"
                    :min="0"
                    :step="0.01"
                    :precision="2"
                    class="w-full"
                    addon-before="₱"
                    placeholder="Count the cash"
                    data-testid="end-shift-counted"
                />
                <p v-if="showExpected" class="mb-0 mt-1 text-xs text-gray-500">
                    Expected: {{ formattedTotal(Number(cashControl.expected_cash) || 0) }}<template v-if="expenseTotal">
                        , less {{ formattedTotal(expenseTotal) }} in expenses below</template>.
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
                <div v-for="(e, i) in form.expenses" :key="i" class="mb-2 grid grid-cols-12 gap-2" data-testid="end-shift-expense">
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

            <a-radio-group v-model:value="form.action" class="flex flex-col gap-2">
                <a-radio value="cashout_now" :disabled="countedAmount <= 0" data-testid="end-shift-cashout">
                    Take the cash out
                    <div class="text-xs text-gray-500">The drawer starts the next shift empty.</div>
                </a-radio>
                <a-radio value="save_as_opening_cash" data-testid="end-shift-keep">
                    Leave it as the next shift's opening
                    <div class="text-xs text-gray-500">The cash stays in the drawer.</div>
                </a-radio>
            </a-radio-group>

            <a-textarea v-model:value="form.notes" :rows="2" placeholder="Notes (optional)" />

            <div v-if="needsManagerPin">
                <label class="mb-1 block text-sm font-medium text-gray-700">Manager's PIN</label>
                <a-input-password v-model:value="form.pin" placeholder="A manager approves the cash" autocomplete="off" data-testid="end-shift-pin" />
            </div>
        </div>
        <template #footer>
            <a-button @click="emit('close')">Cancel</a-button>
            <a-button
                type="primary"
                :loading="saving"
                :disabled="missingCount || (needsManagerPin && !form.pin)"
                data-testid="confirm-end-shift"
                @click="submit"
            >
                End shift
            </a-button>
        </template>
    </a-modal>
</template>
