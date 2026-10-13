<script setup>
const props = defineProps({
    title: {
        type: String,
        default: "Title",
    },
    filterMainClass: {
        type: String,
        default:
            "flex justify-between lg:flex-row xl:flex-row flex-col lg:items-center xl:items-center items-start mb-3 w-full",
    },
    filterClass: {
        type: String,
        default:
            "flex justify-end lg:space-x-3 xl:lg:space-x-3 space-x-1 lg:w-5/6 xl:w-5/6 w-full",
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
                    <h1 class="text-lg font-semibold text-nowrap">
                        {{ title }}
                    </h1>

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
