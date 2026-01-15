<template>
  <div v-if="overdueAccounts.length > 0" class="mb-4">
    <a-alert
      type="warning"
      show-icon
      :message="`${overdueAccounts.length} Overdue Account${overdueAccounts.length > 1 ? 's' : ''}`"
      :description="`Total overdue amount: ₱${totalOverdueAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`"
      closable
      @close="() => {}"
    >
      <template #action>
        <a-button size="small" @click="$emit('refresh')" :loading="loading">
          Refresh
        </a-button>
      </template>
    </a-alert>
  </div>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  overdueAccounts: {
    type: Array,
    default: () => [],
  },
  loading: Boolean,
});

const emit = defineEmits(["refresh"]);

const totalOverdueAmount = computed(() => {
  return props.overdueAccounts.reduce((sum, account) => {
    return sum + (account.overdue_amount || 0);
  }, 0);
});
</script>
