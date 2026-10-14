<script setup>
const props = defineProps({
    title: {
        type: String,
        default: "Title",
    },
    filterMainClass: {
        type: String,
        default:
            "flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between mb-3 w-full",
    },
    filterClass: {
        type: String,
        default: "flex min-w-0 w-full xl:flex-1 justify-end",
    },
});
</script>

<!-- Fills the height it is given; the products and the cart scroll inside their own cards. -->
<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="rounded-lg w-full flex min-h-0 flex-1 gap-4">
            <div
                class="flex min-h-0 w-[70%] flex-col p-6 bg-white rounded-lg shadow"
            >
                <div class="shrink-0" :class="filterMainClass">
                    <div class="flex shrink-0 items-center gap-2 xl:min-h-[32px]">
                        <h1 class="m-0 text-lg font-semibold text-nowrap">
                            {{ title }}
                        </h1>
                        <slot name="titleMeta" />
                    </div>

                    <div :class="filterClass">
                        <slot name="filters" />
                    </div>
                </div>
                <div class="shrink-0">
                    <slot name="activeFilters" />
                </div>
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <slot name="table" />
                </div>
            </div>
            <div
                class="flex min-h-0 w-[30%] flex-col overflow-hidden bg-white p-6 rounded-lg shadow"
            >
                <slot name="right-side-content" />
            </div>
        </div>
        <div class="shrink-0">
            <slot name="footer" />
        </div>
    </div>
</template>
