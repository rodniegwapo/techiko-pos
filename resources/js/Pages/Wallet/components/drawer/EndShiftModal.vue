<script setup>
import { computed, ref, watch } from "vue";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * End the shift: the cash has to be counted first. The counted cash is either taken out of the
 * drawer, or left in it as the next shift's opening cash.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    cashControl: { type: Object, required: true },
    saving: { type: Boolean, default: false },
});
const emit = defineEmits(["close", "end", "count"]);
const { formattedTotal } = useHelpers();

const counted = computed(() => props.cashControl.counted_at != null);
const countedAmount = computed(() => Number(props.cashControl.counted_cash || 0));
const action = ref("cashout_now");

watch(
    () => props.open,
    (open) => {
        if (open) action.value = countedAmount.value > 0 ? "cashout_now" : "save_as_opening_cash";
    },
    { immediate: true },
);
</script>

<template>
    <a-modal
        :visible="open"
        title="End shift"
        destroy-on-close
        @cancel="emit('close')"
    >
        <div v-if="!counted" class="pt-1 text-sm text-gray-700" data-testid="end-shift-count-first">
            Count the cash first, so the shift ends with what is really in the drawer.
        </div>
        <div v-else class="flex flex-col gap-3 pt-1">
            <p class="m-0 text-sm text-gray-700">
                Counted: <span class="font-semibold">{{ formattedTotal(countedAmount) }}</span>. What happens to it?
            </p>
            <a-radio-group v-model:value="action" class="flex flex-col gap-2">
                <a-radio value="cashout_now" :disabled="countedAmount <= 0" data-testid="end-shift-cashout">
                    Take the cash out
                    <div class="text-xs text-gray-500">The drawer starts the next shift empty.</div>
                </a-radio>
                <a-radio value="save_as_opening_cash" data-testid="end-shift-keep">
                    Leave it as the next shift's opening
                    <div class="text-xs text-gray-500">The cash stays in the drawer.</div>
                </a-radio>
            </a-radio-group>
        </div>
        <template #footer>
            <a-button @click="emit('close')">Cancel</a-button>
            <a-button v-if="!counted" type="primary" @click="emit('count')">Count cash</a-button>
            <a-button v-else type="primary" :loading="saving" data-testid="confirm-end-shift" @click="emit('end', action)">
                End shift
            </a-button>
        </template>
    </a-modal>
</template>
