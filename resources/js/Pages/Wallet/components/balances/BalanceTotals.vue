<script setup>
import { computed } from "vue";
import { useHelpers } from "@/Composables/useHelpers";
import { PLACE_GROUPS, groupPlaces } from "../walletLabels";

/**
 * Where money is, totalled: everything the store holds now, then the cash drawer, e-wallets, and
 * banks and card terminals, each with its share of the total.
 */
const props = defineProps({
    /** [{ id, name, kind, balance }] */
    channels: { type: Array, default: () => [] },
    drawerExpected: { type: Number, default: null },
});
const { formattedTotal } = useHelpers();
const money = (v) => formattedTotal(Number(v) || 0);

const places = computed(() => [
    ...(props.drawerExpected !== null ? [{ kind: "drawer", balance: props.drawerExpected }] : []),
    ...props.channels.map((c) => ({ kind: c.kind, balance: c.balance })),
]);
const total = computed(() => Math.round(places.value.reduce((sum, p) => sum + (Number(p.balance) || 0), 0) * 100) / 100);
const groups = computed(() => {
    const found = Object.fromEntries(groupPlaces(places.value).map((g) => [g.key, g]));
    return PLACE_GROUPS.map((g) => ({
        ...g,
        subtotal: found[g.key]?.subtotal ?? 0,
        count: found[g.key]?.places.length ?? 0,
        share: total.value > 0 ? Math.round(((found[g.key]?.subtotal ?? 0) / total.value) * 1000) / 10 : 0,
    }));
});
</script>

<template>
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="balance-totals">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm" data-testid="balance-total">
            <div class="text-xs font-medium uppercase text-gray-500">Total money</div>
            <div class="mt-1 font-mono text-xl font-bold tabular-nums text-gray-900">{{ money(total) }}</div>
            <div class="text-xs text-gray-500">{{ places.length }} places</div>
        </div>
        <div v-for="g in groups" :key="g.key" class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm" :data-testid="`balance-total-${g.key}`">
            <div class="text-xs font-medium uppercase text-gray-500">{{ g.label }}</div>
            <div class="mt-1 font-mono text-xl font-bold tabular-nums" :class="g.subtotal < 0 ? 'text-red-700' : 'text-gray-900'">{{ money(g.subtotal) }}</div>
            <div class="mt-1 h-1.5 w-full overflow-hidden rounded bg-gray-100">
                <div class="h-full rounded bg-green-600" :style="{ width: `${Math.max(0, Math.min(100, g.share))}%` }" />
            </div>
            <div class="mt-1 text-xs text-gray-500">{{ g.share }}% of total</div>
        </div>
    </div>
</template>
