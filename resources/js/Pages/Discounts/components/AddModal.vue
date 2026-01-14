<script setup>
import { computed, ref } from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import dayjs from "dayjs";

const { spinning } = useTable();
const page = usePage();
const { formData, openModal, isEdit, errors } = useGlobalVariables();
const { inertiaProgressLifecyle } = useHelpers();
const { getRoute } = useDomainRoutes();

const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
});

const domainOptions = computed(() => {
    const list = Array.isArray(page?.props?.domains) ? page.props.domains : [];
    return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

// Handle type value extraction for radio
const handleTypeChange = (e) => {
    formData.value.type = e.target.value;
};

// Handle scope value extraction for select
const handleScopeChange = (value) => {
    formData.value.scope = value;
};

// Get scope value for select binding
const scopeValue = computed({
    get: () => {
        const val = formData.value?.scope;
        if (typeof val === 'object' && val?.value !== undefined) {
            return val.value;
        }
        return val;
    },
    set: (value) => {
        formData.value.scope = value;
    }
});

// Handle domain value extraction for select
const handleDomainChange = (value) => {
    formData.value.domain = value;
};

// Get domain value for select binding
const domainValue = computed({
    get: () => {
        const val = formData.value?.domain;
        if (typeof val === 'object' && val?.value !== undefined) {
            return val.value;
        }
        return val;
    },
    set: (value) => {
        formData.value.domain = value;
    }
});

const handleSave = () => {
    const payload = {
        ...formData.value,
        type: formData.value?.type?.value || formData.value.type,
        scope: formData.value?.scope?.value || formData.value.scope,
        start_date: formData.value.start_date
            ? dayjs(formData.value.start_date).format("YYYY-MM-DD HH:mm:ss")
            : null,
        end_date: formData.value?.end_date
            ? dayjs(formData.value.end_date).format("YYYY-MM-DD HH:mm:ss")
            : null,
    };

    router.post(
        getRoute("products.discounts.store"),
        payload,
        inertiaProgressLifecyle
    );
};

const handleUpdate = () => {
    const payload = {
        ...formData.value,
        type: formData.value?.type?.value || formData.value.type,
        scope: formData.value?.scope?.value || formData.value.scope,
        start_date: formData.value.start_date
            ? dayjs(formData.value.start_date).format("YYYY-MM-DD HH:mm:ss")
            : null,
        end_date: formData.value?.end_date
            ? dayjs(formData.value.end_date).format("YYYY-MM-DD HH:mm:ss")
            : null,
    };

    router.put(
        getRoute("products.discounts.update", {
            discount: formData.value.id,
        }),
        payload,
        inertiaProgressLifecyle
    );
};
</script>

<template>
    <a-modal
        v-model:visible="openModal"
        :title="isEdit ? 'Edit Discount' : 'Add Discount'"
        @cancel="openModal = false"
        :maskClosable="false"
    >
        <a-form layout="vertical">
            <!-- Discount Name -->
            <a-form-item
                label="Discount Name"
                :validate-status="errors.name ? 'error' : ''"
                :help="errors.name || ''"
            >
                <a-input
                    v-model:value="formData.name"
                    placeholder="Enter discount name"
                    size="large"
                />
            </a-form-item>

            <!-- Domain (conditional for global view) -->
            <a-form-item
                v-if="page.props.isGlobalView"
                label="Domain"
                :validate-status="errors.domain ? 'error' : ''"
                :help="errors.domain || ''"
            >
                <a-select
                    v-model:value="domainValue"
                    :options="domainOptions"
                    placeholder="Select domain"
                    size="large"
                    @change="handleDomainChange"
                />
            </a-form-item>

            <!-- Discount Type -->
            <a-form-item
                label="Discount Type"
                :validate-status="errors.type ? 'error' : ''"
                :help="errors.type || ''"
            >
                <a-radio-group
                    :value="formData.type?.value || formData.type"
                    @change="handleTypeChange"
                    size="large"
                >
                    <a-radio value="percentage">Percentage</a-radio>
                    <a-radio value="amount">Amount</a-radio>
                </a-radio-group>
            </a-form-item>

            <!-- Discount Value -->
            <a-form-item
                label="Discount Value"
                :validate-status="errors.value ? 'error' : ''"
                :help="errors.value || ''"
            >
                <a-input-number
                    v-model:value="formData.value"
                    placeholder="Enter discount value"
                    :min="0"
                    :precision="2"
                    style="width: 100%"
                    size="large"
                />
            </a-form-item>

            <!-- Minimum Order Amount -->
            <a-form-item
                label="Minimum Order Amount"
                :validate-status="errors.min_order_amount ? 'error' : ''"
                :help="errors.min_order_amount || ''"
            >
                <a-input-number
                    v-model:value="formData.min_order_amount"
                    placeholder="Enter minimum order amount (optional)"
                    :min="0"
                    :precision="2"
                    style="width: 100%"
                    size="large"
                />
            </a-form-item>

            <!-- Scope -->
            <a-form-item
                label="Scope"
                :validate-status="errors.scope ? 'error' : ''"
                :help="errors.scope || ''"
            >
                <a-select
                    v-model:value="scopeValue"
                    :options="[
                        { label: 'Order', value: 'order' },
                        { label: 'Product', value: 'product' }
                    ]"
                    placeholder="Select scope"
                    size="large"
                    @change="handleScopeChange"
                />
            </a-form-item>

            <!-- Start Date -->
            <a-form-item
                label="Start Date"
                :validate-status="errors.start_date ? 'error' : ''"
                :help="errors.start_date || ''"
            >
                <a-date-picker
                    v-model:value="formData.start_date"
                    placeholder="Select start date and time"
                    show-time
                    format="YYYY-MM-DD HH:mm:ss"
                    style="width: 100%"
                    size="large"
                />
            </a-form-item>

            <!-- End Date -->
            <a-form-item
                label="End Date"
                :validate-status="errors.end_date ? 'error' : ''"
                :help="errors.end_date || ''"
            >
                <a-date-picker
                    v-model:value="formData.end_date"
                    placeholder="Select end date and time"
                    show-time
                    format="YYYY-MM-DD HH:mm:ss"
                    style="width: 100%"
                    size="large"
                />
            </a-form-item>
        </a-form>

        <template #footer>
            <a-button @click="openModal = false">Cancel</a-button>

            <primary-button
                v-if="isEdit"
                :loading="spinning"
                @click="handleUpdate"
                >Update
            </primary-button>
            <primary-button v-else :loading="spinning" @click="handleSave"
                >Submit
            </primary-button>
        </template>
    </a-modal>
</template>
