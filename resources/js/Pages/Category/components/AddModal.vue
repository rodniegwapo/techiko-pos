<script setup>
import { ref, toRefs } from "vue";
import VerticalForm from "@/components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { useEmits } from "@/Composables/useEmits";
import { router } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import { usePage } from "@inertiajs/vue3";

const { spinning } = useTable();
const page = usePage();

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
});

const { emitClose, emitEvent } = useEmits();

const { visible } = toRefs(props);

const formData = ref({});

const formFields = [
  { key: "name", label: "Name", type: "text" },
  { key: "description", label: "Description", type: "textarea", rows: 4 },
];

const errors = ref({});
const handleSave = () => {
  router.post(route("categories.index"), formData.value, {
    onSuccess: () => {
      emitClose();
      formData.value = {};
    },
    onStart: () => {
      spinning.value = true;
    },
    onError: (error) => {
      errors.value = error;
    },
    onFinish: () => {
      spinning.value = false;
    },
  });
};
</script>

<template>
  <a-modal v-model:visible="visible" title="Add Category" @cancel="emitClose">
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
    <template #footer>
      <a-button @click="emitClose">Cancel</a-button>

      <primary-button :loading="spinning" @click="handleSave"
        >Submit</primary-button
      >
    </template>
  </a-modal>
</template>
