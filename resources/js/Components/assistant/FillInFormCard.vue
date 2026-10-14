<script setup>
import { IconPencil } from "@tabler/icons-vue";

/**
 * A fill-in form the AI assistant showed for a change asked without its details. "Fill in" puts the
 * form in the message box; the user completes the lines and sends it back to the assistant.
 */
defineProps({
    form: { type: Object, required: true }, // { action, title, text }
});
const emit = defineEmits(["fill"]);
</script>

<template>
    <div class="mt-2 rounded-lg border border-blue-200 bg-white p-3 text-sm" data-testid="assistant-form-card">
        <p class="mb-2 font-semibold text-gray-900">{{ form.title }}</p>
        <pre class="m-0 whitespace-pre-wrap break-words rounded bg-gray-50 px-2 py-1.5 font-mono text-xs text-gray-700">{{ form.text }}</pre>
        <a-button type="primary" size="small" class="mt-3" data-testid="assistant-form-fill" @click="emit('fill', form.text)">
            <span class="inline-flex items-center gap-1"><IconPencil :size="14" /> Fill in</span>
        </a-button>
    </div>
</template>
