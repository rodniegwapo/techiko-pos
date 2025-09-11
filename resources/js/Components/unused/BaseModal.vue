<script setup>
import { defineProps, defineEmits } from "vue";

const props = defineProps({
  visible: {
    type: Boolean,
    required: true,
  },
  title: {
    type: String,
    default: "",
  },
  okText: {
    type: String,
    default: "OK",
  },
  cancelText: {
    type: String,
    default: "Cancel",
  },
  okButtonProps: {
    type: Object,
    default: () => ({}),
  },
  cancelButtonProps: {
    type: Object,
    default: () => ({}),
  },
  destroyOnClose: {
    type: Boolean,
    default: true,
  },
});

const emit = defineEmits(["update:visible", "ok", "cancel"]);

const handleOk = () => {
  emit("ok");
};

const handleCancel = () => {
  emit("cancel");
  emit("update:visible", false);
};
</script>

<template>
  <a-modal
    :title="title"
    :visible="visible"
    :destroyOnClose="destroyOnClose"
    @ok="handleOk"
    @cancel="handleCancel"
    :footer="null"
  >
    <!-- Default slot for modal content -->
    <slot />

    <!-- Custom footer slot -->
    <template #footer>
      <slot name="footer">
        <a-button @click="handleCancel" v-bind="cancelButtonProps">
          {{ cancelText }}
        </a-button>
        <a-button type="primary" @click="handleOk" v-bind="okButtonProps">
          {{ okText }}
        </a-button>
      </slot>
    </template>
  </a-modal>
</template>
