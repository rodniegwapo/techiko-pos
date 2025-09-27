<template>
  <div class="customer-loyalty-card">
    <div class="flex justify-between items-start">
      <div class="flex-1">
        <h3 class="font-bold text-base">{{ customer.name }}</h3>
        <p class="text-xs text-gray-600">
          {{ customer.phone || customer.email }}
        </p>
        <div class="flex items-center gap-3 mt-1">
          <div class="tier-badge" :style="{ backgroundColor: customer.tier_info.color }">
            <crown-outlined class="text-xs" />
            {{ customer.tier_info.name }}
          </div>
          <div class="points-display">
            <wallet-outlined class="text-xs" />
            {{ customer.loyalty_points?.toLocaleString() || 0 }} pts
          </div>
        </div>
      </div>
      <div class="flex gap-1">
        <a-tooltip title="View customer details">
          <a-button size="small" @click="$emit('viewDetails')">
            <template #icon>
              <eye-outlined />
            </template>
          </a-button>
        </a-tooltip>
        <a-tooltip title="Remove customer">
          <a-button size="small" danger @click="$emit('clearCustomer')">
            <template #icon>
              <close-outlined />
            </template>
          </a-button>
        </a-tooltip>
      </div>
    </div>
    
    <!-- Points Calculation Preview -->
    <div v-if="showPointsPreview && totalAmount > 0" class="mt-2 p-2 bg-purple-50 rounded">
      <div class="text-xs text-purple-700 flex items-center gap-1">
        <gift-outlined />
        Will earn: <span class="font-medium">{{ pointsToEarn }}</span> points
        <span class="text-xs opacity-75">({{ customer.tier_info.multiplier }}x)</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { 
  CrownOutlined, 
  WalletOutlined, 
  EyeOutlined, 
  CloseOutlined,
  GiftOutlined 
} from '@ant-design/icons-vue';

const props = defineProps({
  customer: {
    type: Object,
    required: true
  },
  totalAmount: {
    type: Number,
    default: 0
  },
  showPointsPreview: {
    type: Boolean,
    default: true
  }
});

defineEmits(['viewDetails', 'clearCustomer']);

// Calculate points for current order
const pointsToEarn = computed(() => {
  if (!props.customer || props.totalAmount <= 0) return 0;
  
  const basePoints = Math.floor(props.totalAmount / 10); // 1 point per ₱10
  const multiplier = props.customer.tier_info?.multiplier || 1;
  
  return Math.floor(basePoints * multiplier);
});
</script>

<style scoped>
.customer-loyalty-card {
  @apply p-3 bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-200 rounded-lg shadow-sm;
}

.tier-badge {
  @apply px-2 py-1 rounded-full text-xs font-bold text-white flex items-center gap-1;
}

.points-display {
  @apply flex items-center gap-1 text-xs text-purple-600 font-medium;
}
</style>
