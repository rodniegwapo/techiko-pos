<script setup>
import { ref, watch } from "vue";
import { useHelpers } from "@/Composables/useHelpers";

/** The cash in the drawer when the shift starts. */
const props = defineProps({
    open: { type: Boolean, default: false },
    cashControl: { type: Object, required: true },
    saving: { type: Boolean, default: false },
});
const emit = defineEmits(["close", "save"]);
const { formattedTotal } = useHelpers();

const amount = ref(null);
const reason = ref("");

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const c = props.cashControl;
        amount.value = c.opening_is_saved ? Number(c.opening_cash ?? 0) : Number(c.opening_suggestion ?? c.opening_cash ?? 0);
        reason.value = "";
    },
    { immediate: true },
);
</script>

<template>
    <a-modal
        :visible="open"
        title="Set opening cash"
        ok-text="Save opening"
        :confirm-loading="saving"
        :ok-button-props="{ disabled: !(Number(amount) > 0), 'data-testid': 'save-opening' }"
        destroy-on-close
        @ok="emit('save', amount, reason)"
        @cancel="emit('close')"
    >
        <div class="flex flex-col gap-3 pt-1">
            <p class="m-0 text-sm text-gray-600">How much cash is in the drawer at the start of the shift?</p>
            <p v-if="!cashControl.opening_is_saved && cashControl.suggestion_source_date" class="m-0 text-xs text-amber-700">
                Suggested {{ formattedTotal(Number(cashControl.opening_suggestion) || 0) }}: what was counted on
                {{ cashControl.suggestion_source_date }}.
            </p>
            <a-input-number v-model:value="amount" :min="0" :step="0.01" :precision="2" class="w-full" addon-before="₱" data-testid="opening-amount" />
            <a-textarea v-model:value="reason" :rows="2" placeholder="Why it changed (optional)" />
        </div>
    </a-modal>
</template>
