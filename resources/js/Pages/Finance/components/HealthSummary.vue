<script setup>
defineProps({
    items: { type: Array, default: () => [] },
});

const toneClasses = {
    good: "bg-emerald-50 text-emerald-700 border-emerald-200",
    neutral: "bg-blue-50 text-blue-700 border-blue-200",
    warning: "bg-amber-50 text-amber-700 border-amber-200",
    bad: "bg-red-50 text-red-700 border-red-200",
    muted: "bg-gray-50 text-gray-500 border-gray-200",
};
</script>

<template>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="finance-health">
        <div
            v-for="item in items"
            :key="item.key"
            class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4"
        >
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-gray-900">{{ item.label }}</span>
                <span class="rounded-full border px-2 py-0.5 text-xs font-medium" :class="toneClasses[item.tone] ?? toneClasses.muted">
                    {{ item.status }}
                </span>
            </div>
            <p class="text-sm text-gray-600">{{ item.detail }}</p>
        </div>
    </div>
</template>
