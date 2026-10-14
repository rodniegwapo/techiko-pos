<script setup>
import { computed, ref } from "vue";
import axios from "axios";
import { IconCheck, IconX, IconAlertTriangle } from "@tabler/icons-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

/**
 * A change the AI assistant prepared. Nothing is saved until the user presses Confirm; the server
 * then checks their permission again and runs it through the same code as the normal page.
 */
const props = defineProps({
    action: { type: Object, required: true }, // { token, title, fields, confirm_label, expires_at, state?, result? }
});
const emit = defineEmits(["settled"]);

const { getRoute } = useDomainRoutes();
const busy = ref(false);

const state = computed(() => {
    if (props.action.state) return props.action.state;
    return new Date(props.action.expires_at) < new Date() ? "expired" : "pending";
});

async function send(kind) {
    if (busy.value || state.value !== "pending") return;
    busy.value = true;
    try {
        const { data } = await axios.post(
            getRoute(`assistant.actions.${kind}`, { token: props.action.token }),
        );
        emit("settled", { state: kind === "confirm" ? "done" : "cancelled", message: data.message, errors: null });
    } catch (e) {
        const status = e?.response?.status;
        emit("settled", {
            state: status === 404 ? "expired" : "failed",
            message: e?.response?.data?.message || "Could not save this change. Please try again.",
            errors: e?.response?.data?.errors || null,
        });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        class="mt-2 rounded-lg border bg-white p-3 text-sm"
        :class="{
            'border-blue-200': state === 'pending',
            'border-green-300': state === 'done',
            'border-red-200': state === 'failed',
            'border-gray-200 opacity-75': state === 'cancelled' || state === 'expired',
        }"
        data-testid="assistant-action-card"
    >
        <p class="mb-2 font-semibold text-gray-900">{{ action.title }}</p>
        <dl class="grid grid-cols-[auto,1fr] gap-x-3 gap-y-1">
            <template v-for="field in action.fields" :key="field.label">
                <dt class="text-gray-500">{{ field.label }}</dt>
                <dd class="break-words text-gray-900">{{ field.value }}</dd>
            </template>
        </dl>

        <div v-if="state === 'pending'" class="mt-3 flex gap-2">
            <a-button type="primary" size="small" :loading="busy" data-testid="assistant-action-confirm" @click="send('confirm')">
                {{ action.confirm_label || "Confirm" }}
            </a-button>
            <a-button size="small" :disabled="busy" @click="send('cancel')">Cancel</a-button>
        </div>
        <p v-else-if="state === 'done'" class="mt-3 flex items-center gap-1 text-green-700">
            <IconCheck :size="16" /> {{ action.result?.message || "Saved." }}
        </p>
        <div v-else-if="state === 'failed'" class="mt-3 text-red-600">
            <p class="flex items-center gap-1"><IconAlertTriangle :size="16" /> {{ action.result?.message }}</p>
            <ul v-if="action.result?.errors" class="mt-1 list-disc pl-5">
                <template v-for="(messages, field) in action.result.errors" :key="field">
                    <li v-for="m in messages" :key="m">{{ m }}</li>
                </template>
            </ul>
            <p class="mt-1 text-xs text-gray-500">Nothing was changed. Tell the assistant what to fix and it will prepare it again.</p>
        </div>
        <p v-else-if="state === 'cancelled'" class="mt-3 flex items-center gap-1 text-gray-500">
            <IconX :size="16" /> Cancelled. Nothing was changed.
        </p>
        <p v-else class="mt-3 text-gray-500">This request expired. Ask the assistant again.</p>
    </div>
</template>
