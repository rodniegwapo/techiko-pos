<script setup>
import { computed } from "vue";
import { CloseOutlined } from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const { formFilters } = useGlobalVariables();

const props = defineProps({
  title: {
    type: String,
    default: "Active Filters:",
  },
  clearTooltip: {
    type: String,
    default: "Remove all filters",
  },
});

// 🔹 Build active filters list from global formFilters
const activeFilters = computed(() => {
  return Object.entries(formFilters.value)
    .filter(([_, v]) => v && (Array.isArray(v) ? v.length > 0 : v !== ""))
    .map(([key, value]) => ({
      key,
      value: Array.isArray(value) ? value.join(", ") : value,
    }));
});

const hasActiveFilters = computed(() => activeFilters.value.length > 0);

// remove a single filter
const removeFilter = (key) => {
  const newFilters = { ...formFilters.value };
  delete newFilters[key];
  formFilters.value = newFilters;
};

// clear all filters
const clearAll = () => {
  formFilters.value = {};
};
</script>

<template>
  <div v-if="hasActiveFilters">
    <hr class="-mx-6 border-t-[3px] mt-[20px] m-[20px]" />
    <div class="flex justify-between items-start mb-3">
      <!-- Active Filters List -->
      <div class="flex items-start space-x-2 flex-wrap">
        <h3 class="text-sm">{{ title }}</h3>
        <template v-for="(item, index) in activeFilters" :key="index">
          <a-tag closable color="blue" @close="removeFilter(item.key)">
            {{ item.value }}
          </a-tag>
        </template>
      </div>

      <!-- Clear All Button -->
      <a-tooltip>
        <template #title>
          {{ clearTooltip }}
        </template>
        <a-button
          type="link"
          class="-mt-[7px] text-gray-400"
          @click.prevent="clearAll"
        >
          <template #icon>
            <CloseOutlined />
          </template>
        </a-button>
      </a-tooltip>
    </div>
  </div>
</template>
