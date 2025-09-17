<script setup>
import { computed, ref, toRefs } from "vue";
import VerticalForm from "@/components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { useEmits } from "@/Composables/useEmits";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import dayjs from "dayjs";

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
  },
  {
    key: "type",
    label: "Discount Type",
    type: "radio",
    options: [ {label: 'Percentage', value: 'percentage',lable: 'Amount', value: 'amount'}],
  },
  {
    key: "value",
    label: "Discount Value",
    type: "number",
  },
  {
    key: "min_order_amount",
    label: "Minimum Order Amount",
    type: "number",
  },
  {
    key: "scope",
    label: "Scope",
    type: "select",
    options: [
      { label: "Order", value: "order" },
      { label: "Product", value: "product" },
    ],
  },
  {
    key: "start_date",
    label: "Start Date",
    type: "datetime",
  },
  {
    key: "end_date",
    label: "End Date",
    type: "datetime",
  },
];

const errors = ref({});
const handleSave = () => {
  const payload = {
    ...formData.value,
    scope: formData.value?.scope?.value,
    start_date: dayjs(formData.value.start_date).format("YYYY-MM-DD hh:mm:ss"),
    end_date: dayjs(formData.value?.end_date).format("YYYY-MM-DD hh:mm:ss"),
  };

  router.post(
    route("products.discounts.store"),
    payload,
    inertiaProgressLifecyle
  );
};

const handleUpdate = () => {
  const payload = {
    ...formData.value,
    start_date: dayjs(formData.value.start_date).format("YYYY-MM-DD hh:mm:ss"),
    end_date: dayjs(formData.value?.end_date).format("YYYY-MM-DD hh:mm:ss"),
  };

  router.put(
    route("products.discounts.update", {
      id: formData.value.id,
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
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />

    <template #footer>
      <a-button @click="openModal = false">Cancel</a-button>

      <primary-button v-if="isEdit" :loading="spinning" @click="handleUpdate"
        >Update
      </primary-button>
      <primary-button v-else :loading="spinning" @click="handleSave"
        >Submit
      </primary-button>
    </template>
  </a-modal>
</template>
