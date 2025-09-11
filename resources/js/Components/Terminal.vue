<script setup>
import VerticalForm from "./Forms/VerticalForm.vue";
import PrimaryButton from "./PrimaryButton.vue";

import { ref, toRefs, onMounted } from "vue";
import axios from "axios";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";

const { formData, spinning, errors } = useGlobalVariables();

const { getDeviceId } = useHelpers();

const formFields = [
  {
    key: "name",
    label: "Device Name ",
    type: "text",
  },
  {
    key: "password",
    label: "User Password ",
    type: "password",
  },
];

const terminalModal = ref(false);
const loading = ref(false);

const handleSave = async () => {
  try {
    loading.value = true;
    const response = await axios.post(route("setup.terminal"), formData.value);
    localStorage.setItem("device_id", response?.data?.uuid);
    location.reload();
  } catch (error) {
    errors.value = error?.response?.data?.errors;
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  if (getDeviceId.value) {
    terminalModal.value = false;
    return;
  }

  return (terminalModal.value = true);
});
</script>

<template>
  <a-modal
    v-model:visible="terminalModal"
    title="Register Device"
    :closable="false"
    :maskClosable="false"
  >
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />

    <template #footer>
      <primary-button :loading="loading" @click="handleSave"
        >Submit
      </primary-button>
    </template>
  </a-modal>
</template>