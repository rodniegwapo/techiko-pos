<script setup>
defineProps({
    title: {
        type: String,
        default: "Title",
    },
    orderCollapsed: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div class="cs-stage flex h-full min-h-0 w-full flex-col overflow-hidden rounded-lg bg-white shadow">
        <div
            class="flex min-h-0 w-full flex-1 flex-col overflow-hidden md:flex-row"
        >
            <!-- Menu / products -->
            <div
                class="flex h-full min-h-0 min-w-0 flex-col px-4 pt-4 pb-24 sm:px-6 sm:pt-5 md:pb-5"
                :class="
                    orderCollapsed ? 'md:flex-1 md:w-auto' : 'md:w-[68%]'
                "
            >
                <div v-if="$slots.hero" class="mb-4 shrink-0">
                    <slot name="hero" />
                </div>
                <div
                    v-else
                    class="mb-4 flex shrink-0 flex-col gap-3 md:flex-row md:items-end md:justify-between"
                >
                    <h1
                        class="cs-display text-2xl font-semibold text-[var(--cs-ink)]"
                    >
                        {{ title }}
                    </h1>
                    <div
                        class="flex flex-wrap items-center justify-end gap-2 md:max-w-[70%]"
                    >
                        <slot name="filters" />
                    </div>
                </div>

                <div
                    v-if="$slots.hero"
                    class="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-2"
                >
                    <div class="text-sm font-semibold text-[var(--cs-ink)]">
                        All items
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <slot name="filters" />
                    </div>
                </div>

                <div class="min-h-0 shrink-0">
                    <slot name="activeFilters" />
                </div>

                <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <slot name="table" />
                </div>
            </div>

            <!-- Order rail (desktop) -->
            <div
                class="cs-order-rail hidden min-h-0 min-w-0 overflow-hidden md:flex md:h-full md:min-h-0 md:flex-col"
                :class="
                    orderCollapsed
                        ? 'md:w-52 md:shrink-0 md:px-3 md:py-5'
                        : 'md:w-[32%] md:px-5 md:py-6'
                "
            >
                <div class="flex h-full min-h-0 flex-1 flex-col overflow-hidden">
                    <slot name="right-side-content" />
                </div>
            </div>
        </div>

        <slot name="mobile-actions" />

        <div class="shrink-0">
            <slot name="footer" />
        </div>
    </div>
</template>
