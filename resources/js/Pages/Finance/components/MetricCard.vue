<script setup>
import { computed } from "vue";
import { IconArrowUpRight, IconArrowDownRight } from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";

const props = defineProps({
    label: { type: String, required: true },
    value: { type: Number, default: 0 },
    /** { amount, pct } from the server; omit when the figure has no previous period. */
    change: { type: Object, default: null },
    /** Whether a rise is good news (sales) or bad news (costs). */
    upIsGood: { type: Boolean, default: true },
    hint: { type: String, default: "" },
    muted: { type: Boolean, default: false },
});

const { formattedTotal } = useHelpers();

const trend = computed(() => {
    const pct = props.change?.pct;
    if (pct === null || pct === undefined) return null;
    const up = pct >= 0;
    const good = pct === 0 ? null : up === props.upIsGood;
    return {
        up,
        text: `${up ? "+" : ""}${pct}%`,
        className: good === null ? "text-gray-500" : good ? "text-emerald-600" : "text-red-600",
    };
});
</script>

<template>
    <div class="flex h-full w-full flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex items-start justify-between gap-2">
            <span class="text-xs font-medium text-gray-500">{{ label }}</span>
            <slot name="action" />
        </div>
        <div class="mt-1 text-xl font-semibold" :class="muted ? 'text-gray-400' : 'text-gray-900'">
            <slot name="value">{{ formattedTotal(value) }}</slot>
        </div>
        <div v-if="trend" class="mt-1 flex items-center gap-1 text-xs" :class="trend.className">
            <IconArrowUpRight v-if="trend.up" :size="14" />
            <IconArrowDownRight v-else :size="14" />
            <span class="font-medium">{{ trend.text }}</span>
            <span class="text-gray-500">vs previous period</span>
        </div>
        <div v-else-if="change" class="mt-1 text-xs text-gray-500">No earlier figure to compare</div>
        <p v-if="hint" class="mt-2 text-xs text-gray-500">{{ hint }}</p>
    </div>
</template>
