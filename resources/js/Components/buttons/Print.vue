<script setup>
import { onMounted, ref } from "vue";
import { PrinterOutlined } from "@ant-design/icons-vue";
defineProps({
  loading: {
    type: Boolean,
    defaultl: false,
  },
});
</script>

<template>
  <a-button type="primary"
    ><template #icon> <PrinterOutlined /> </template>{{ $t("Print") }}</a-button
  >
</template>
