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
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const { spinning } = useTable();
const page = usePage();
const { formData, openModal, isEdit } = useGlobalVariables();
const { inertiaProgressLifecyle } = useHelpers();
const { getRoute } = useDomainRoutes();

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
});

const categoriesOption = computed(() => {
  const list = Array.isArray(page?.props?.categories)
    ? page.props.categories
    : [];
  return list.map((item) => ({ label: item.name, value: item.id }));
});

const soltTypeOptions = computed(() => {
  const list = Array.isArray(page?.props?.sold_by_types)
    ? page.props.sold_by_types
    : [];
  return list.map((item) => item.name);
});

const formFields = computed(() => [
  { key: "name", label: "Product Name", type: "text" },
  {
    key: "category_id",
    label: "Category",
    type: "select",
    options: categoriesOption.value,
  },
  { key: "cost", label: "Cost", type: "number" },
  { key: "price", label: "Price", type: "number" },
  { key: "SKU", label: "SKU", type: "text" },
  { key: "barcode", label: "Barcode", type: "text" },
  {
    key: "sold_type",
    label: "Sold Type",
    type: "radio",
    options: soltTypeOptions.value,
  },
  {
    key: "representation_type",
    label: "Representation Type",
    type: "select",
    options: ["color"],
  },
  {
    key: "representation",
    label: "Representation",
    type: "text",
  },
]);

const errors = ref({});
const handleSave = () => {
  formData.value.category_id = formData.value?.category_id?.value;
  router.post(getRoute("products.store"), formData.value, inertiaProgressLifecyle);
};

const handleUpdate = () => {
  formData.value.category_id = formData.value?.category_id?.value;
  router.put(
    getRoute("products.update", {
      product: formData.value.id,
    }),
    formData.value,
    inertiaProgressLifecyle
  );
};
</script>

<template>
  <a-modal
    v-model:visible="openModal"
    :title="isEdit ? 'Edit Product' : 'Add Product'"
    @cancel="openModal = false"
    :maskClosable="false"
  >

    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
    <!-- <a-upload
      v-model:file-list="fileList"
      name="file"
      action="https://www.mocky.io/v2/5cc8019d300000980a055e76"
      :headers="headers"
      @change="handleChange"
    >
      <a-button>
        <upload-outlined></upload-outlined>
        Click to Upload
      </a-button>
    </a-upload> -->
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
