<script setup>
import { computed, ref, watch } from "vue";
import { useHelpers } from "@/Composables/useHelpers";
import { shortOver, TONE_CLASSES } from "../walletLabels";
import BillCounter from "./BillCounter.vue";

/**
 * Spot check my drawer: an optional count of your own tray during your shift, to see it's on
 * track (short or over against your shift's Expected as you type). It doesn't end the shift; it's
 * kept with the shift's counts.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** Your shift's Expected now. */
    expected: { type: Number, required: true },
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
        amount.value = null;
        notes.value = "";
    },
    { immediate: true },
);

const missing = computed(() => amount.value === null || amount.value === "" || !Number.isFinite(Number(amount.value)));
const preview = computed(() => (missing.value ? null : shortOver(Number(amount.value) - Number(props.expected || 0))));
</script>

<template>
    <a-modal
        :visible="open"
        title="Spot check my drawer"
        ok-text="Save spot check"
        :confirm-loading="saving"
        :ok-button-props="{ disabled: missing, 'data-testid': 'save-count' }"
        destroy-on-close
        @ok="emit('save', amount, notes)"
        @cancel="emit('close')"
    >
        <div class="flex flex-col gap-3 pt-1">
            <p class="m-0 text-sm text-gray-600">
                Count your own tray now to see if it's on track. It doesn't end your shift; it's kept with your shift's counts.
            </p>
            <p class="m-0 text-sm text-gray-600">
                Expected now: <span class="font-semibold" data-testid="count-expected">{{ formattedTotal(Number(expected) || 0) }}</span>
            </p>
            <BillCounter v-model="amount" testid="count-amount" prefix="count" />
            <p v-if="preview && showResult" class="m-0 text-sm font-medium" :class="TONE_CLASSES[preview.tone]" data-testid="count-preview">
                {{ preview.amount ? `${preview.label} ${formattedTotal(preview.amount)}` : preview.label }}
            </p>
            <a-textarea v-model:value="notes" :rows="2" placeholder="Notes (optional)" />
        </div>
    </a-modal>
</template>
