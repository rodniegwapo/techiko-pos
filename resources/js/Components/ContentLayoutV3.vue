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

<!-- Fills the height it is given; the products scroll inside the card (bottom room for the checkout bar). -->
<template>
    <div class="flex w-full min-w-0 min-h-0 flex-1 flex-col">
        <div
            class="flex w-full min-h-0 flex-1 flex-col rounded-lg bg-white p-3 pb-0 shadow sm:p-4 sm:pb-0 min-w-0"
        >
            <div class="shrink-0" :class="filterMainClass">
                <h1 class="text-lg font-semibold">{{ title }}</h1>
                <div :class="filterClass">
                    <slot name="filters" />
                </div>
            </div>
            <div class="shrink-0">
                <slot name="activeFilters" />
            </div>
            <div class="flex min-h-0 flex-1 flex-col overflow-hidden pb-20">
                <slot name="table" />
            </div>
        </div>
        <slot name="mobile-actions" />
    </div>
</template>
