<script setup>
import { ref, toRefs } from "vue";
import VerticalForm from "@/components/Forms/VerticalForm.vue";

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
});

const { visible } = toRefs(props);

const formData = ref({});

const formFields = [
  { key: "name", label: "Name", type: "text" },
  {
    key: "gender",
    label: "Gender",
    type: "radio",
    options: ["Male", "Female"],
  },
  { key: "birthday", label: "Birthday", type: "date", open: false },
  { key: "age", label: "Age", type: "number" },
  { key: "bio", label: "Bio", type: "textarea" },
  { key: "appointment", label: "Appointment", type: "datetime" },
  { key: "alarm", label: "Alarm Time", type: "time" },
  {
    key: "role_id",
    label: "Role",
    type: "select",
    multiple: "multiple",
    options: [
      { id: 1, name: "Admin" },
      { id: 2, name: "User" },
      { id: 3, name: "Guest" },
    ],
  },
  {
    key: "agree",
    label: "Agreement",
    type: "checkbox-single",
    text: "I agree to the terms and conditions",
  },
  {
    key: "fruits",
    label: "Select Fruits",
    type: "checkbox-group",
    checkAll: true,
    options: [
      { label: "Apple", value: "Apple" },
      { label: "Pear", value: "Pear" },
      { label: "Orange", value: "Orange", disabled: true },
    ],
  },
];
const plainOptions = [
  {
    label: "Apple",
    value: "Apple",
  },
  {
    label: "Pear",
    value: "Pear",
  },
  {
    label: "Orange",
    value: "Orange",
  },
];
</script>

<template>
  <!-- <a-checkbox-group
    v-model:value="value1"
    name="checkboxgroup"
    :options="plainOptions"
  /> -->
  {{ formData }}
  <a-modal v-model:visible="visible" title="Basic Modal" @ok="handleOk">
    <vertical-form v-model="formData" :fields="formFields" />
  </a-modal>
</template>
