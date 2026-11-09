<script setup>
import { useHelpers } from "@/Composables/useHelpers";

/**
 * Today's drawer at a glance, for supervisors and up: the cash in every tray, today's cash sales,
 * what went past the drawer (card, e-wallet, bank), and the counts waiting for review.
 */
const props = defineProps({
    /** { cash_in_drawers, cash_sales, digital, discrepancy, needs_review } */
    summary: { type: Object, required: true },
    /** Cashiers on shift now, for the drawer card's note. */
    onShift: { type: Number, default: 0 },
});
const emit = defineEmits(["review"]);
const { formattedTotal } = useHelpers();
const money = (v) => formattedTotal(Number(v) || 0);
</script>

<template>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="drawer-summary">
        <div class="min-w-0 rounded-lg border border-gray-200 bg-white px-3 py-3 shadow-sm md:px-4" data-testid="summary-cash-in-drawers">
            <div class="text-xs font-medium uppercase text-gray-500">Cash in drawers</div>
            <div class="mt-1 break-words font-mono text-lg font-bold md:text-xl tabular-nums text-gray-900">{{ money(summary.cash_in_drawers) }}</div>
            <div class="text-xs text-gray-500">{{ onShift }} on shift now</div>
        </div>
        <div class="min-w-0 rounded-lg border border-gray-200 bg-white px-3 py-3 shadow-sm md:px-4" data-testid="summary-cash-sales">
            <div class="text-xs font-medium uppercase text-gray-500">Cash sales today</div>
            <div class="mt-1 break-words font-mono text-lg font-bold md:text-xl tabular-nums text-gray-900">{{ money(summary.cash_sales) }}</div>
            <div class="text-xs text-gray-500">Into the drawers</div>
        </div>
        <div class="min-w-0 rounded-lg border border-gray-200 bg-white px-3 py-3 shadow-sm md:px-4" data-testid="summary-digital">
            <div class="text-xs font-medium uppercase text-gray-500">Card, e-wallet &amp; bank</div>
            <div class="mt-1 break-words font-mono text-lg font-bold md:text-xl tabular-nums text-gray-900">{{ money(summary.digital) }}</div>
            <div class="text-xs text-gray-500">Today, past the drawer</div>
        </div>
        <div
            v-if="summary.needs_review !== null"
            class="min-w-0 rounded-lg border px-3 py-3 shadow-sm md:px-4"
            :class="summary.needs_review ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white'"
            data-testid="summary-discrepancy"
        >
            <div class="text-xs font-medium uppercase" :class="summary.needs_review ? 'text-red-700' : 'text-gray-500'">Counts off</div>
            <div class="mt-1 break-words font-mono text-lg font-bold md:text-xl tabular-nums" :class="summary.discrepancy < 0 ? 'text-red-700' : summary.discrepancy > 0 ? 'text-amber-700' : 'text-gray-900'">
                {{ summary.discrepancy < 0 ? "−" : summary.discrepancy > 0 ? "+" : "" }}{{ money(Math.abs(summary.discrepancy)) }}
            </div>
            <div class="text-xs" :class="summary.needs_review ? 'text-red-700' : 'text-gray-500'">
                <template v-if="summary.needs_review">
                    {{ summary.needs_review }} waiting for review ·
                    <a-button type="link" size="small" class="h-auto !p-0 text-xs" @click="emit('review')">Review</a-button>
                </template>
                <template v-else>Nothing waiting for review</template>
            </div>
        </div>
    </div>
</template>
