<script setup>
import { computed, ref, watch } from "vue";
import { useHelpers } from "@/Composables/useHelpers";
import { shortOver, TONE_CLASSES } from "../walletLabels";

/** Count the cash in the drawer; shows short or over against Expected as you type. */
const props = defineProps({
    open: { type: Boolean, default: false },
    cashControl: { type: Object, required: true },
    saving: { type: Boolean, default: false },
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
        title="Count cash"
        ok-text="Save count"
        :confirm-loading="saving"
        :ok-button-props="{ disabled: missing, 'data-testid': 'save-count' }"
        destroy-on-close
        @ok="emit('save', amount, notes)"
        @cancel="emit('close')"
    >
        <div class="flex flex-col gap-3 pt-1">
            <p class="m-0 text-sm text-gray-600">
                Count the cash in the drawer. Expected: <span class="font-semibold">{{ formattedTotal(Number(cashControl.expected_cash) || 0) }}</span>
            </p>
            <a-input-number v-model:value="amount" :min="0" :step="0.01" :precision="2" class="w-full" addon-before="₱" data-testid="count-amount" />
            <p v-if="preview" class="m-0 text-sm font-medium" :class="TONE_CLASSES[preview.tone]" data-testid="count-preview">
                {{ preview.amount ? `${preview.label} ${formattedTotal(preview.amount)}` : preview.label }}
            </p>
            <a-textarea v-model:value="notes" :rows="2" placeholder="Notes (optional)" />
        </div>
    </a-modal>
</template>
