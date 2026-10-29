<script setup>
import { computed } from "vue";
import KitchenTicket from "@/Components/Receipt/KitchenTicket.vue";

/**
 * A kitchen ticket just made, to print: a table's order sent from its tab, or a take-out order
 * paid at the till. Closed with Done.
 */
const props = defineProps({
    /** KitchenTicket::toPrint(), or null when there's nothing to show. */
    ticket: { type: Object, default: null },
});
const emit = defineEmits(["close"]);

const printId = computed(() => `kitchen-ticket-${props.ticket?.id ?? "x"}`);
</script>

<template>
    <a-modal :visible="!!ticket" title="Sent to the kitchen" :width="380" @cancel="emit('close')">
        <div v-if="ticket" class="flex justify-center rounded border bg-white p-2" data-testid="kitchen-ticket">
            <div :id="printId"><KitchenTicket :ticket="ticket" /></div>
        </div>
        <template #footer>
            <a-button data-testid="kitchen-ticket-done" @click="emit('close')">Done</a-button>
            <span v-print="{ id: printId, popTitle: ticket ? `Kitchen ${ticket.label}` : 'Kitchen' }">
                <a-button type="primary" data-testid="kitchen-ticket-print">Print</a-button>
            </span>
        </template>
    </a-modal>
</template>
