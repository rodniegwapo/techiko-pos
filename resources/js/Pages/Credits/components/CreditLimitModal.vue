<script setup>
import { ref, watch, computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { useCredit } from "@/Composables/useCredit";
import { notification } from "ant-design-vue";

const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() =>
    isMdUp.value ? 520 : "calc(100vw - 24px)",
);
const modalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);
const modalBodyStyle = computed(() =>
    isMdUp.value ? {} : { maxHeight: "calc(100vh - 120px)", overflowY: "auto" },
);

const props = defineProps({
    visible: Boolean,
    customer: Object,
});

const emit = defineEmits(["close", "saved"]);

// Local state to control modal visibility
const localVisible = ref(props.visible);

// Sync localVisible with prop
watch(
    () => props.visible,
    (val) => {
        localVisible.value = val;
    }
);

// Composable
const { updateCreditSettings, loading } = useCredit();

const formData = ref({
    credit_limit: 0,
    credit_terms_days: 30,
    credit_enabled: false,
});

// Sync formData with customer
watch(
    () => props.customer,
    (customer) => {
        if (customer) {
            formData.value = {
                credit_limit: customer.credit_limit || 0,
                credit_terms_days: customer.credit_terms_days || 30,
                credit_enabled: customer.credit_enabled || false,
            };
        }
    },
    { immediate: true }
);

const handleSubmit = async () => {
    try {
        await updateCreditSettings(props.customer.id, formData.value);
        localVisible.value = false;
        emit("saved");
    } catch (error) {
        // Error handled in composable
    }
};

const handleCancel = () => {
    localVisible.value = false;
    emit("close");
};
</script>

<template>
    <a-modal
        :visible="localVisible"
        title="Credit Settings"
        :confirm-loading="loading"
        :width="modalWidth"
        :style="modalRootStyle"
        :body-style="modalBodyStyle"
        centered
        @ok="handleSubmit"
        @cancel="handleCancel"
    >
        <a-form :model="formData" layout="vertical">
            <div class="grid grid-cols-1 gap-0 md:grid-cols-2 md:gap-x-4">
            <a-form-item label="Credit Limit" required>
                <a-input-number
                    v-model:value="formData.credit_limit"
                    :min="0"
                    :precision="2"
                    :step="100"
                    style="width: 100%"
                    placeholder="Enter credit limit"
                />
            </a-form-item>

            <a-form-item label="Payment Terms (Days)">
                <a-input-number
                    v-model:value="formData.credit_terms_days"
                    :min="1"
                    :max="365"
                    style="width: 100%"
                    placeholder="Enter payment terms in days"
                />
            </a-form-item>

            <a-form-item class="md:col-span-2">
                <a-checkbox v-model:checked="formData.credit_enabled">
                    Enable Credit for this Customer
                </a-checkbox>
            </a-form-item>
            </div>
        </a-form>
    </a-modal>
</template>
