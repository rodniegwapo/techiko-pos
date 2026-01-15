<template>
    <a-modal
        :visible="visible"
        title="Record Payment"
        :confirm-loading="loading"
        @ok="handleSubmit"
        @cancel="handleCancel"
        width="600px"
    >
        <a-form :model="formData" layout="vertical" ref="formRef">
            <!-- Amount -->
            <a-form-item
                label="Amount"
                name="amount"
                :rules="[
                    { required: true, message: 'Please enter payment amount' },
                ]"
            >
                <a-input-number
                    :value="formData.amount"
                    @change="(val) => (formData.amount = val)"
                    :min="0.01"
                    :max="maxAmount"
                    :precision="2"
                    :step="100"
                    style="width: 100%"
                    placeholder="Enter payment amount"
                />
                <div class="text-sm text-gray-500 mt-1">
                    Current balance: ₱{{
                        (customer?.credit_balance || 0).toLocaleString(
                            "en-US",
                            {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2,
                            }
                        )
                    }}
                </div>
            </a-form-item>

            <!-- Payment Method -->
            <a-form-item label="Payment Method" name="payment_method">
                <a-select
                    :value="formData.payment_method"
                    @change="(val) => (formData.payment_method = val)"
                    placeholder="Select payment method"
                >
                    <a-select-option value="cash">Cash</a-select-option>
                    <a-select-option value="card">Card</a-select-option>
                    <a-select-option value="e-wallet">E-Wallet</a-select-option>
                </a-select>
            </a-form-item>

            <!-- Apply to Invoices -->
            <a-form-item label="Apply to Invoices (Optional)">
                <a-checkbox-group
                    :value="formData.transaction_ids"
                    @change="(val) => (formData.transaction_ids = val)"
                >
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        <a-checkbox
                            v-for="invoice in outstandingInvoices"
                            :key="invoice.id"
                            :value="invoice.id"
                        >
                            <div class="flex justify-between w-full">
                                <span>{{
                                    invoice.reference_number ||
                                    invoice.sale?.invoice_number ||
                                    "N/A"
                                }}</span>
                                <span class="ml-4 text-gray-500">
                                    ₱{{
                                        invoice.amount.toLocaleString("en-US", {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2,
                                        })
                                    }}
                                </span>
                            </div>
                        </a-checkbox>
                    </div>
                </a-checkbox-group>
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
import { ref, computed, watch } from "vue";
import { useCredit } from "@/Composables/useCredit";
import { notification } from "ant-design-vue";

// Props
const props = defineProps({
    visible: Boolean,
    customer: Object,
    outstandingInvoices: {
        type: Array,
        default: () => [],
    },
});

// Emits
const emit = defineEmits(["close", "saved"]);

// Composable
const { recordPayment, loading } = useCredit();

// Form reference
const formRef = ref(null);

// Form data
const formData = ref({
    amount: null,
    payment_method: "cash",
    transaction_ids: [],
    reference_number: "",
    notes: "",
});

// Max amount computed
const maxAmount = computed(() => props.customer?.credit_balance || 0);

// Reset form when modal opens
watch(
    () => props.visible,
    (visible) => {
        if (visible) {
            formData.value = {
                amount: null,
                payment_method: "cash",
                transaction_ids: [],
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

        if (formData.value.amount > maxAmount.value) {
            notification.error({
                message: "Error",
                description: `Payment amount cannot exceed current balance of ₱${maxAmount.value.toLocaleString(
                    "en-US",
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    }
                )}`,
            });
            return;
        }

        await recordPayment(props.customer.id, {
            transaction_type: "payment",
            amount: formData.value.amount,
            payment_method: formData.value.payment_method,
            transaction_ids:
                formData.value.transaction_ids.length > 0
                    ? formData.value.transaction_ids
                    : undefined,
            reference_number: formData.value.reference_number || undefined,
            notes: formData.value.notes || undefined,
        });

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
