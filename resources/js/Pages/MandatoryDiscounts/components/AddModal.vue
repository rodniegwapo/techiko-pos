<script setup>
import { computed, ref } from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";

const { spinning } = useTable();
const page = usePage();
const { formData, openModal, isEdit, errors } = useGlobalVariables();
const { inertiaProgressLifecyle } = useHelpers();

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

// Handle type value extraction for select
const handleTypeChange = (value) => {
    formData.value.type = value;
};

// Get type value for select binding
const typeValue = computed({
    get: () => {
        const val = formData.value?.type;
        if (typeof val === 'object' && val?.value !== undefined) {
            return val.value;
        }
        return val;
    },
    set: (value) => {
        formData.value.type = value;
    }
});

// Handle is_active value extraction for select
const handleIsActiveChange = (value) => {
    formData.value.is_active = value === true || value === 'true' || value === 1;
};

// Get is_active value for select binding
const isActiveValue = computed({
    get: () => {
        const val = formData.value?.is_active;
        if (typeof val === 'object' && val?.value !== undefined) {
            return val.value === true || val.value === 'true' || val.value === 1;
        }
        return val === true || val === 'true' || val === 1;
    },
    set: (value) => {
        formData.value.is_active = value === true || value === 'true' || value === 1;
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
    is_active:
      formData.value?.is_active?.value !== undefined
        ? formData.value.is_active.value === true || formData.value.is_active.value === 'true' || formData.value.is_active.value === 1
        : formData.value.is_active !== undefined
        ? formData.value.is_active === true || formData.value.is_active === 'true' || formData.value.is_active === 1
        : true,
  };

  router.post(
    route("mandatory-discounts.store"),
    payload,
    inertiaProgressLifecyle
  );
};

const handleUpdate = () => {
  const payload = {
    ...formData.value,
    type: formData.value?.type?.value || formData.value.type,
    is_active:
      formData.value?.is_active?.value !== undefined
        ? formData.value.is_active.value === true || formData.value.is_active.value === 'true' || formData.value.is_active.value === 1
        : formData.value.is_active !== undefined
        ? formData.value.is_active === true || formData.value.is_active === 'true' || formData.value.is_active === 1
        : true,
  };

  router.put(
    route("mandatory-discounts.update", {
      mandatory_discount: formData.value.id,
    }),
    payload,
    inertiaProgressLifecyle
  );
};

const modalTitle = computed(() => {
  return isEdit.value ? "Edit Mandatory Discount" : "Create Mandatory Discount";
});
</script>

<template>
  <a-modal
    v-model:visible="openModal"
    :title="modalTitle"
    width="500px"
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
          placeholder="e.g., Senior Citizen, PWD, Student"
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
        <a-select
          v-model:value="typeValue"
          :options="[
            { label: 'Percentage', value: 'percentage' },
            { label: 'Amount', value: 'amount' }
          ]"
          placeholder="Select discount type"
          size="large"
          @change="handleTypeChange"
        />
      </a-form-item>

      <!-- Discount Value -->
      <a-form-item
        label="Discount Value"
        :validate-status="errors.value ? 'error' : ''"
        :help="errors.value || ''"
      >
        <a-input-number
          v-model:value="formData.value"
          placeholder="Enter discount value (e.g., 20 for 20% or 100 for ₱100)"
          :min="0"
          :precision="2"
          style="width: 100%"
          size="large"
        />
      </a-form-item>

      <!-- Status -->
      <a-form-item
        label="Status"
        :validate-status="errors.is_active ? 'error' : ''"
        :help="errors.is_active || ''"
      >
        <a-select
          v-model:value="isActiveValue"
          :options="[
            { label: 'Active', value: true },
            { label: 'Inactive', value: false }
          ]"
          placeholder="Select status"
          size="large"
          @change="handleIsActiveChange"
        />
      </a-form-item>
    </a-form>

    <template #footer>
      <a-button @click="openModal = false">Cancel</a-button>

      <primary-button v-if="isEdit" :loading="spinning" @click="handleUpdate">
        Update
      </primary-button>
      <primary-button v-else :loading="spinning" @click="handleSave">
        Submit
      </primary-button>
    </template>
  </a-modal>
</template>
