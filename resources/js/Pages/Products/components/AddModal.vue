<script setup>
import { computed } from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import {
  validationHasError,
  validationMessage,
} from "@/Composables/useValidationMessage.js";

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

const domainOptions = computed(() => {
  const list = Array.isArray(page?.props?.domains)
    ? page.props.domains
    : [];
  return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

const isOrganizationProductForm = computed(
  () => !page.props.isGlobalView,
);

// Handle category_id value extraction for select
const handleCategoryChange = (value) => {
  formData.value.category_id = value;
};

// Get category_id value for select binding
const categoryIdValue = computed({
  get: () => {
    const val = formData.value?.category_id;
    if (typeof val === 'object' && val?.value !== undefined) {
      return val.value;
    }
    return val;
  },
  set: (value) => {
    formData.value.category_id = value;
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

// Handle representation_type value extraction
const handleRepresentationTypeChange = (value) => {
  formData.value.representation_type = value;
};

// Get representation_type value for select binding
const representationTypeValue = computed({
  get: () => {
    const val = formData.value?.representation_type;
    if (typeof val === 'object' && val?.value !== undefined) {
      return val.value;
    }
    return val;
  },
  set: (value) => {
    formData.value.representation_type = value;
  }
});

const handleSave = () => {
  // Ensure category_id is a number, not an object
  if (typeof formData.value.category_id === 'object' && formData.value.category_id?.value !== undefined) {
    formData.value.category_id = formData.value.category_id.value;
  }
  router.post(getRoute("products.store"), formData.value, inertiaProgressLifecyle);
};

const handleUpdate = () => {
  // Ensure category_id is a number, not an object
  if (typeof formData.value.category_id === 'object' && formData.value.category_id?.value !== undefined) {
    formData.value.category_id = formData.value.category_id.value;
  }
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
    <a-form layout="vertical">
      <!-- Product Name -->
      <a-form-item
        label="Product Name"
        required
        :validate-status="validationHasError(errors, 'name') ? 'error' : ''"
        :help="validationMessage(errors, 'name')"
      >
        <a-input
          v-model:value="formData.name"
          placeholder="Enter product name"
          size="large"
        />
      </a-form-item>

      <!-- Domain (conditional for global view) -->
      <a-form-item
        v-if="page.props.isGlobalView"
        label="Domain"
        required
        :validate-status="validationHasError(errors, 'domain') ? 'error' : ''"
        :help="validationMessage(errors, 'domain')"
      >
        <a-select
          v-model:value="domainValue"
          :options="domainOptions"
          placeholder="Select domain"
          size="large"
          @change="handleDomainChange"
        />
      </a-form-item>

      <!-- Category -->
      <a-form-item
        label="Category"
        :required="isOrganizationProductForm"
        :validate-status="validationHasError(errors, 'category_id') ? 'error' : ''"
        :help="validationMessage(errors, 'category_id')"
      >
        <a-select
          v-model:value="categoryIdValue"
          :options="categoriesOption"
          placeholder="Select category"
          show-search
          :filter-option="(input, option) => option.label.toLowerCase().includes(input.toLowerCase())"
          size="large"
          @change="handleCategoryChange"
        />
      </a-form-item>

      <!-- Cost -->
      <a-form-item
        label="Cost"
        :validate-status="validationHasError(errors, 'cost') ? 'error' : ''"
        :help="validationMessage(errors, 'cost')"
      >
        <a-input-number
          v-model:value="formData.cost"
          placeholder="Enter cost"
          :min="0"
          :precision="2"
          style="width: 100%"
          size="large"
        />
      </a-form-item>

      <!-- Price -->
      <a-form-item
        label="Price"
        required
        :validate-status="validationHasError(errors, 'price') ? 'error' : ''"
        :help="validationMessage(errors, 'price')"
      >
        <a-input-number
          v-model:value="formData.price"
          placeholder="Enter price"
          :min="0"
          :precision="2"
          style="width: 100%"
          size="large"
        />
      </a-form-item>

      <!-- SKU -->
      <a-form-item
        label="SKU"
        :required="page.props.isGlobalView"
        :validate-status="validationHasError(errors, 'SKU') ? 'error' : ''"
        :help="validationMessage(errors, 'SKU')"
      >
        <a-input
          v-model:value="formData.SKU"
          placeholder="Enter SKU"
          size="large"
        />
      </a-form-item>

      <!-- Barcode -->
      <a-form-item
        label="Barcode"
        :required="page.props.isGlobalView"
        :validate-status="validationHasError(errors, 'barcode') ? 'error' : ''"
        :help="validationMessage(errors, 'barcode')"
      >
        <a-input
          v-model:value="formData.barcode"
          placeholder="Enter barcode"
          size="large"
        />
      </a-form-item>

      <!-- Sold Type -->
      <a-form-item
        label="Sold Type"
        required
        :validate-status="validationHasError(errors, 'sold_type') ? 'error' : ''"
        :help="validationMessage(errors, 'sold_type')"
      >
        <a-radio-group
          v-model:value="formData.sold_type"
          size="large"
        >
          <a-radio
            v-for="option in soltTypeOptions"
            :key="option"
            :value="option"
          >
            {{ option }}
          </a-radio>
        </a-radio-group>
      </a-form-item>

      <!-- Representation Type (edit only; create uses server default color) -->
      <a-form-item
        v-if="isEdit"
        label="Representation Type"
        :validate-status="validationHasError(errors, 'representation_type') ? 'error' : ''"
        :help="validationMessage(errors, 'representation_type')"
      >
        <a-select
          v-model:value="representationTypeValue"
          :options="[{ label: 'Color', value: 'color' }]"
          placeholder="Select representation type"
          size="large"
          @change="handleRepresentationTypeChange"
        />
      </a-form-item>

      <!-- Representation -->
      <a-form-item
        label="Representation"
        :validate-status="validationHasError(errors, 'representation') ? 'error' : ''"
        :help="validationMessage(errors, 'representation')"
      >
        <a-input
          v-model:value="formData.representation"
          placeholder="Enter representation (e.g., hex color code)"
          size="large"
        />
      </a-form-item>
    </a-form>

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
