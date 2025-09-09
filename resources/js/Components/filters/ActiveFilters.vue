<script setup>
import { computed } from "vue";
import { CloseOutlined } from "@ant-design/icons-vue";

const props = defineProps({
  filters: {
    type: Array,
    default: () => [],
  },
  title: {
    type: String,
    default: "Active Filters:",
  },
  clearTooltip: {
    type: String,
    default: "Remove all filters",
  },
});

const hasActiveFilters = computed(() => props.filters.some((f) => f.value));
</script>

<template>
  <div v-if="hasActiveFilters">
    <hr class="-mx-6 border-t-[3px] mt-[20px] m-[20px]" />
    <div class="flex justify-between items-start mb-3">
      <!-- Active Filters List -->
      <div class="flex items-start space-x-2 flex-wrap">
        <h3 class="text-sm">{{ title }}</h3>
        <template v-for="(item, index) in filters" :key="index">
          <a-tag
            v-if="item.value"
            closable
            color="green"
            @close="$emit('remove-filter', item.key)"
          >
            <span v-if="item.label">{{ item.label }} : </span> {{ item.value }}
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
          @click.prevent="$emit('clear-all')"
        >
          <template #icon>
            <CloseOutlined />
          </template>
        </a-button>
      </a-tooltip>
    </div>
  </div>
</template>
