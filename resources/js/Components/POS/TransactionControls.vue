<template>
  <div class="flex items-center gap-2 p-2 bg-gray-50 rounded-lg">
    <!-- Hold Transaction Button -->
    <a-tooltip title="Hold current transaction for later">
      <a-button 
        type="default"
        :disabled="orders.length === 0"
        @click="$emit('holdTransaction')"
        class="flex items-center gap-1"
      >
        <hold-outlined />
        Hold
      </a-button>
    </a-tooltip>

    <!-- Held Transactions Button -->
    <a-tooltip title="View and recall held transactions">
      <a-badge :count="heldTransactions.length" :offset="[5, -5]">
        <a-button 
          type="default"
          @click="$emit('showHeldTransactions')"
          class="flex items-center gap-1"
        >
          <folder-open-outlined />
          Held
        </a-button>
      </a-badge>
    </a-tooltip>

    <!-- Activity Indicator -->
    <div v-if="orders.length > 0" class="flex items-center gap-1 text-xs text-gray-500 ml-auto">
      <clock-circle-outlined />
      <span>Active: {{ timeAgo }}</span>
    </div>

    <!-- Offline Status -->
    <div v-if="isOffline" class="flex items-center gap-1 text-xs text-orange-600">
      <wifi-outlined style="transform: rotate(45deg);" />
      <span>Offline</span>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { 
  HoldOutlined, 
  FolderOpenOutlined, 
  ClockCircleOutlined,
  WifiOutlined
} from '@ant-design/icons-vue';

const props = defineProps({
  orders: Array,
  heldTransactions: Array,
  lastActivity: Number,
  isOffline: Boolean,
});

defineEmits(['holdTransaction', 'showHeldTransactions']);

const now = ref(Date.now());

const timeAgo = computed(() => {
  if (!props.lastActivity) return '';
  
  const diff = now.value - props.lastActivity;
  const minutes = Math.floor(diff / 60000);
  const seconds = Math.floor((diff % 60000) / 1000);
  
  if (minutes > 0) {
    return `${minutes}m ${seconds}s`;
  }
  return `${seconds}s`;
});

let interval;

onMounted(() => {
  interval = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});

onUnmounted(() => {
  if (interval) clearInterval(interval);
});
</script>
