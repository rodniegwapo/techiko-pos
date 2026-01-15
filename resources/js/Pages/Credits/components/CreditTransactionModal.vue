<template>
    <a-modal
        :visible="visible"
        title="Add Credit Transaction"
        :confirm-loading="loading"
        @ok="handleSubmit"
        @cancel="handleCancel"
        width="600px"
    >
        <a-form :model="formData" layout="vertical" ref="formRef">
            <!-- Transaction Type -->
            <a-form-item
                label="Transaction Type"
                name="transaction_type"
                :rules="[
                    {
                        required: true,
                        message: 'Please select transaction type',
                    },
                ]"
            >
                <a-select
                    :value="formData.transaction_type"
                    @change="(val) => (formData.transaction_type = val)"
                    placeholder="Select transaction type"
                >
                    <a-select-option value="adjustment"
                        >Adjustment</a-select-option
                    >
                    <a-select-option value="refund">Refund</a-select-option>
                </a-select>
            </a-form-item>

            <!-- Amount -->
            <a-form-item
                label="Amount"
                name="amount"
                :rules="[{ required: true, message: 'Please enter amount' }]"
            >
                <a-input-number
                    :value="formData.amount"
                    @change="(val) => (formData.amount = val)"
                    :min="
                        formData.transaction_type === 'adjustment'
                            ? undefined
                            : 0.01
                    "
                    :precision="2"
                    :step="100"
                    style="width: 100%"
                    :placeholder="
                        formData.transaction_type === 'adjustment'
                            ? 'Enter amount (positive to increase, negative to decrease)'
                            : 'Enter amount'
                    "
                />
                <div
                    v-if="formData.transaction_type === 'adjustment'"
                    class="text-sm text-gray-500 mt-1"
                >
                    Use positive value to increase balance, negative to decrease
                </div>
            </a-form-item>

            <!-- Reference Number -->
            <a-form-item label="Reference Number" name="reference_number">
                <a-input
                    v-model:value="formData.reference_number"
                    placeholder="Enter reference number (optional)"
                />
            </a-form-item>

            <!-- Notes -->
            <a-form-item label="Notes" name="notes">
                <a-textarea
                    v-model:value="formData.notes"
                    :rows="3"
                    placeholder="Enter notes (optional)"
                />
            </a-form-item>
        </a-form>
    </a-modal>
</template>

<script setup>
import { ref, watch } from "vue";
import { useCredit } from "@/Composables/useCredit";

// Props
const props = defineProps({
    visible: Boolean,
    customer: Object,
});

// Emits
const emit = defineEmits(["close", "saved"]);

// Composable
const { recordPayment, loading } = useCredit();

// Form reference
const formRef = ref(null);

// Form data
const formData = ref({
    transaction_type: "adjustment",
    amount: null,
    reference_number: "",
    notes: "",
});

// Reset form when modal opens
watch(
    () => props.visible,
    (visible) => {
        if (visible) {
            formData.value = {
                transaction_type: "adjustment",
                amount: null,
                reference_number: "",
                notes: "",
            };
        }
    }
);

// Submit handler
const handleSubmit = async () => {
    try {
        await formRef.value.validate();

        const payload = {
            transaction_type: formData.value.transaction_type,
            amount: formData.value.amount,
            reference_number: formData.value.reference_number || undefined,
            notes: formData.value.notes || undefined,
        };

        await recordPayment(props.customer.id, payload);
        emit("saved");
    } catch (error) {
        // Error handled in composable
    }
};

// Cancel handler
const handleCancel = () => {
    emit("close");
};
</script>
