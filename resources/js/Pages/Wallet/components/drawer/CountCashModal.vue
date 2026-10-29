<script setup>
import { computed, ref, watch } from "vue";
import { useHelpers } from "@/Composables/useHelpers";
import { shortOver, TONE_CLASSES } from "../walletLabels";
import BillCounter from "./BillCounter.vue";

/**
 * A spot check: an optional count during the day, to see the drawer is on track (short or over
 * against Expected as you type). End the day still takes the final count, starting from this one.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    cashControl: { type: Object, required: true },
    saving: { type: Boolean, default: false },
    /** Off for cashiers: they count, a manager sees short or over. */
    showResult: { type: Boolean, default: true },
});
const emit = defineEmits(["close", "save"]);
const { formattedTotal } = useHelpers();

const amount = ref(null);
const notes = ref("");

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        amount.value = props.cashControl.counted_cash != null ? Number(props.cashControl.counted_cash) : null;
        notes.value = props.cashControl.notes || "";
    },
    { immediate: true },
);

const missing = computed(() => amount.value === null || amount.value === "" || !Number.isFinite(Number(amount.value)));
const preview = computed(() =>
    missing.value ? null : shortOver(Number(amount.value) - Number(props.cashControl.expected_cash || 0)),
);
</script>

<template>
    <a-modal
        :visible="open"
        title="Spot check"
        ok-text="Save spot check"
        :confirm-loading="saving"
        :ok-button-props="{ disabled: missing, 'data-testid': 'save-count' }"
        destroy-on-close
        @ok="emit('save', amount, notes)"
        @cancel="emit('close')"
    >
        <div class="flex flex-col gap-3 pt-1">
            <p class="m-0 text-sm text-gray-600">
                Count the drawer now to see if it's on track. You'll still count once more when you end the day; this number is filled in for you there.
            </p>
            <p class="m-0 text-sm text-gray-600">
                Expected now: <span class="font-semibold">{{ formattedTotal(Number(cashControl.expected_cash) || 0) }}</span>
            </p>
            <BillCounter v-model="amount" testid="count-amount" prefix="count" />
            <p v-if="preview && showResult" class="m-0 text-sm font-medium" :class="TONE_CLASSES[preview.tone]" data-testid="count-preview">
                {{ preview.amount ? `${preview.label} ${formattedTotal(preview.amount)}` : preview.label }}
            </p>
            <a-textarea v-model:value="notes" :rows="2" placeholder="Notes (optional)" />
        </div>
    </a-modal>
</template>
