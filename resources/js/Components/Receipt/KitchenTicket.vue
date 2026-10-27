<script setup>
/**
 * Kitchen ticket for an 80mm thermal roll: the table or name, when and who sent it, and only the
 * items new since the last send ("ADDITION" on follow-ups). No prices: it is for the cooks.
 * Expects a ticket from tabs.send / tabs.tickets.
 */
import { formatQuantity } from "@/Utils/quantity";

defineProps({
    ticket: { type: Object, required: true },
});
</script>

<template>
    <div class="kitchen-ticket">
        <div class="center big bold">{{ ticket.label }}</div>
        <div v-if="ticket.is_addition" class="center bold">*** ADDITION ***</div>
        <div class="center">Ticket #{{ ticket.number }} · {{ ticket.sent_at }}</div>
        <div v-if="ticket.sent_by" class="center">{{ ticket.sent_by }}</div>
        <div class="rule" />
        <div v-for="(item, i) in ticket.items" :key="i" class="item">
            <div class="row bold">
                <span class="qty">{{ formatQuantity(item.quantity, item) }}</span>
                <span class="name">{{ item.name }}</span>
            </div>
            <div v-for="(m, j) in item.modifiers" :key="j" class="sub">+ {{ m }}</div>
            <div v-if="item.notes" class="sub">* {{ item.notes }}</div>
        </div>
        <div class="rule" />
    </div>
</template>

<style scoped>
.kitchen-ticket {
    width: 72mm;
    padding: 2mm;
    font-family: "Courier New", monospace;
    font-size: 13px;
    line-height: 1.35;
    color: #000;
    background: #fff;
}
.center { text-align: center; }
.bold { font-weight: 700; }
.big { font-size: 20px; }
.rule { border-top: 1px dashed #000; margin: 4px 0; }
.row { display: flex; gap: 8px; }
.qty { min-width: 3ch; }
.name { flex: 1; }
.item { margin-bottom: 4px; font-size: 15px; }
.sub { padding-left: 4ch; font-size: 13px; font-weight: 400; }
</style>
