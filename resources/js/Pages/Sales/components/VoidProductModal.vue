<script setup>
import { ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    visible: { type: Boolean, default: false },
    title: { type: String, default: 'Void Product' },
    itemFieldLabel: { type: String, default: 'Item' },
    submitLoading: { type: Boolean, default: false },
    amount: { type: [String, Number], default: '' },
    itemLabel: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:visible', 'submit']);

const pinCode = ref('');
const reason = ref('');

function fieldError(key) {
    const e = props.errors?.[key];
    if (!e) return '';
    return Array.isArray(e) ? e[0] : e;
}

watch(
    () => props.visible,
    (open) => {
        if (open) {
            pinCode.value = '';
            reason.value = '';
        }
    },
);

function handleSubmit() {
    if (props.submitLoading) return;
    emit('submit', {
        pin_code: pinCode.value,
        reason: reason.value,
    });
}

// Enter submits the void. stop() keeps the key from the POS barcode listener on the page, which
// would otherwise read the PIN as a scan and put it in the product search.
function onEnter(event) {
    event.preventDefault();
    event.stopPropagation();
    handleSubmit();
}

// In the reason box, Shift+Enter still starts a new line.
function onReasonEnter(event) {
    if (event.shiftKey) {
        event.stopPropagation();
        return;
    }
    onEnter(event);
}

function handleCancel() {
    emit('update:visible', false);
}
</script>

<template>
    <a-modal
        :visible="visible"
        :title="title"
        :mask-closable="false"
        width="450px"
        @update:visible="emit('update:visible', $event)"
        @cancel="handleCancel"
    >
        <a-form
            layout="vertical"
            class="max-h-[400px] overflow-y-auto overflow-x-hidden"
        >
            <a-form-item label="Amount">
                <a-input :value="amount" size="large" disabled />
            </a-form-item>
            <a-form-item :label="itemFieldLabel">
                <a-input :value="itemLabel" size="large" disabled />
            </a-form-item>
            <a-form-item
                label="Enter Pin"
                :validate-status="fieldError('pin_code') ? 'error' : ''"
                :help="fieldError('pin_code')"
            >
                <a-input
                    v-model:value="pinCode"
                    type="password"
                    size="large"
                    autocomplete="new-password"
                    @keydown.enter="onEnter"
                />
            </a-form-item>
            <a-form-item
                label="Reason"
                :validate-status="fieldError('reason') ? 'error' : ''"
                :help="fieldError('reason')"
            >
                <a-textarea
                    v-model:value="reason"
                    :rows="3"
                    size="large"
                    @keydown.enter="onReasonEnter"
                />
            </a-form-item>
        </a-form>
        <template #footer>
            <a-button @click="handleCancel">Cancel</a-button>
            <primary-button :loading="submitLoading" @click="handleSubmit">
                Submit
            </primary-button>
        </template>
    </a-modal>
</template>
