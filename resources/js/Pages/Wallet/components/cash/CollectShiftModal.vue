<script setup>
import { ref, watch } from "vue";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";
import { router } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * The owner collects an approved shift's cash into Cash on hand: it leaves the store's drawer and
 * is still the business's money. Up to what was counted (less, to leave change in the drawer).
 */
const props = defineProps({
    /** The shift row (toRow), or null when closed. */
    shift: { type: Object, default: null },
    /** The store's name, when the page shows several stores. */
    store: { type: String, default: null },
});
const emit = defineEmits(["close"]);

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const amount = ref(null);
const notes = ref("");
const busy = ref(false);
const error = ref(null);

watch(
    () => props.shift,
    (shift) => {
        amount.value = shift ? Number(shift.counted_cash) : null;
        notes.value = "";
        error.value = null;
    },
    { immediate: true },
);

function collect() {
    busy.value = true;
    error.value = null;
    router.post(
        getRoute("cash-on-hand.collect", { registerShift: props.shift.id }),
        { amount: amount.value, notes: notes.value.trim() || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                notification.success({ message: `Collected ${formattedTotal(amount.value)} into Cash on hand.` });
                emit("close");
            },
            onError: (errors) => (error.value = Object.values(errors || {})[0] || "Could not collect."),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <a-modal
        :visible="!!shift"
        title="Collect into Cash on hand"
        ok-text="Collect"
        :ok-button-props="{ 'data-testid': 'collect-confirm', disabled: !amount || amount <= 0 }"
        :confirm-loading="busy"
        @ok="collect"
        @cancel="emit('close')"
    >
        <template v-if="shift">
            <p class="text-sm text-gray-600">
                {{ shift.user?.name }}'s shift on {{ dayjs(shift.business_date).format("MMM D") }}<template v-if="store"> at {{ store }}</template>:
                counted {{ formattedTotal(Number(shift.counted_cash) || 0) }}, approved<template v-if="shift.reviewed_by"> by {{ shift.reviewed_by }}</template>.
            </p>
            <a-form layout="vertical">
                <a-form-item label="Amount you're taking" :validate-status="error ? 'error' : ''" :help="error">
                    <a-input-number
                        v-model:value="amount"
                        :min="0.01"
                        :max="Number(shift.counted_cash)"
                        :precision="2"
                        prefix="₱"
                        class="w-full"
                        data-testid="collect-amount"
                    />
                    <p class="mb-0 mt-1 text-xs text-gray-500">
                        Take less to leave change in the drawer. It leaves the drawer today and stays the business's money.
                    </p>
                </a-form-item>
                <a-form-item label="Note (optional)" class="mb-0">
                    <a-input v-model:value="notes" :maxlength="255" data-testid="collect-notes" />
                </a-form-item>
            </a-form>
        </template>
    </a-modal>
</template>
