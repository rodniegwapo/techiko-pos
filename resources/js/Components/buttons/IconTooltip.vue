<script setup>
defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  name: {
    type: String,
    default: "Name",
  },
  hover: {
    type: String,
    default: "", // e.g., "hover:bg-blue-500 hover:text-white"
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});
</script>

<template>
  <a-tooltip>
    <template #title>
      <span>{{ name }}</span>
    </template>

    <a-button
      class="rounded border-none text-white transition-colors duration-200"
      :class="['bg-gray-400', hover]"
      :loading="loading"
      :disabled="disabled"
      type="primary"
    >
      <template #icon>
        <slot />
      </template>
    </a-button>
  </a-tooltip>
</template>
