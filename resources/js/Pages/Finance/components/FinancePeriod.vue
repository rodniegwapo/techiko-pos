<script setup>
import { IconCalendarEvent } from "@tabler/icons-vue";

/** The period on screen and the one it is compared with, under the page title (as on Sales History). */
defineProps({
    label: { type: String, required: true },
    previous: { type: String, default: "" },
    note: { type: String, default: "" },
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600">
        <IconCalendarEvent :size="18" class="text-gray-400" />
        <span class="font-medium text-gray-800" data-testid="finance-period-label">{{ label }}</span>
        <span v-if="previous" class="text-gray-500">compared with {{ previous }}</span>
        <span v-if="note" class="text-gray-500">· {{ note }}</span>
    </div>
</template>
