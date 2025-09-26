<script setup>
import { computed, ref, toRefs } from "vue";
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { useEmits } from "@/Composables/useEmits";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";

const { spinning } = useTable();
const page = usePage();
const { formData, openModal, isEdit } = useGlobalVariables();
const { inertiaProgressLifecyle } = useHelpers();

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
});

const formFields = [
  {
    key: "name",
    label: "Discount Name",
    type: "text",
    placeholder: "e.g., Senior Citizen, PWD, Student",
  },
  {
    key: "type",
    label: "Discount Type",
    type: "select",
    options: [
      { label: "Percentage", value: "percentage" },
      { label: "Amount", value: "amount" },
    ],
  },
  {
    key: "value",
    label: "Discount Value",
    type: "number",
    placeholder: "Enter discount value (e.g., 20 for 20% or 100 for ₱100)",
  },
  {
    key: "is_active",
    label: "Status",
    type: "select",
    options: [
      { label: "Active", value: true },
      { label: "Inactive", value: false },
    ],
  },
];

const errors = ref({});

const handleSave = () => {
  const payload = {
    ...formData.value,
    type: formData.value?.type?.value || formData.value?.type,
    is_active:
      formData.value?.is_active?.value !== undefined
        ? formData.value?.is_active?.value
        : formData.value?.is_active !== undefined
        ? formData.value?.is_active
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
    type: formData.value?.type?.value || formData.value?.type,
    is_active:
      formData.value?.is_active?.value !== undefined
        ? formData.value?.is_active?.value
        : formData.value?.is_active !== undefined
        ? formData.value?.is_active
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
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />

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
