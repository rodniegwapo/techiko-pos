<script setup>
defineProps({
    title: {
        type: String,
        default: "Title",
    },
    filterMainClass: {
        type: String,
        default:
            "flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mb-3 w-full",
    },
    filterClass: {
        type: String,
        default: "flex flex-wrap justify-end gap-2 w-full sm:w-auto",
    },
});
</script>

<template>
    <div class="w-full min-w-0">
        <div>
            <slot name="offline-info" />
        </div>
        <div
            class="w-full rounded-lg bg-white p-3 shadow sm:p-4 min-w-0 pb-20"
        >
            <div :class="filterMainClass">
                <h1 class="text-lg font-semibold">{{ title }}</h1>
                <div :class="filterClass">
                    <slot name="filters" />
                </div>
            </div>
            <div>
                <slot name="activeFilters" />
                <slot name="table" />
            </div>
        </div>
        <slot name="mobile-actions" />
    </div>
</template>
