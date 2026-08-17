<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import dayjs from "dayjs";

defineProps({
    title: String,
    isDashboard: {
        type: Boolean,
        default: false,
    },
});

const now = ref(dayjs());
let clockTimer = null;

onMounted(() => {
    clockTimer = setInterval(() => {
        now.value = dayjs();
    }, 1000);
});

onBeforeUnmount(() => {
    if (clockTimer) {
        clearInterval(clockTimer);
    }
});
</script>

<template>
    <div>
        <div
            class="mb-2 flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="mb-0 min-w-0 text-xl font-semibold">
                {{ title }}
            </p>
            <div
                class="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto sm:justify-end"
            >
                <slot name="actions" />
            </div>
        </div>

        <p class="mb-4 min-w-0 break-words text-xs text-gray-500">
            <span v-if="isDashboard">Here's your dashboard today</span>
            <span v-if="isDashboard"> · </span>
            <span>{{ now.format("dddd, MMMM D, YYYY HH:mm:ss") }}</span>
            <template v-if="$slots.meta">
                <span> | </span>
                <slot name="meta" />
            </template>
        </p>
    </div>
</template>
