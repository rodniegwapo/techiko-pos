<script setup>
defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  /** Native button type inside <form> (Ant Design `html-type`): button | submit | reset */
  htmlType: {
    type: String,
    default: "button",
    validator: (v) => ["button", "submit", "reset"].includes(v),
  },
});
</script>

<template>
  <a-button
    :html-type="htmlType"
    :loading="loading"
    type="primary"
    class="bg-green-700 border border-green-700 hover:bg-green-500 hover:border-green-500"
  >
    <slot />
  </a-button>
</template>
