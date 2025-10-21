<script setup>
import { ref, toRefs } from "vue";
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

const { emitClose, emitEvent } = useEmits();

const formFields = [
  { key: "name", label: "Name", type: "text" },
  { key: "description", label: "Description", type: "textarea", rows: 4 },
];

const errors = ref({});
const handleSave = () => {
  router.post(
    getRoute("categories.store"),
    formData.value,
    inertiaProgressLifecyle
  );
};

const handleUpdate = () => {
  router.put(
    getRoute("categories.update", {
      category: formData.value.id,
    }),
    formData.value,
    inertiaProgressLifecyle
  );
};
</script>

<template>
  <a-modal
    v-model:visible="openModal"
    :title=" isEdit ? 'Edit Category' : 'Add Category'"
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
