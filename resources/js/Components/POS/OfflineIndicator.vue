<template>
  <!-- Offline Status Indicator -->
  <div v-if="isOffline" class="fixed top-4 right-4 z-50">
    <a-alert
      message="Offline Mode"
      description="Working offline. Changes will sync when connection is restored."
      type="warning"
      show-icon
      :closable="false"
      class="shadow-lg"
    />
  </div>
  
  <!-- Offline Queue Indicator -->
  <div v-if="offlineQueue.length > 0" class="fixed bottom-4 right-4 z-50">
    <a-badge :count="offlineQueue.length" :offset="[-5, 5]">
      <a-button 
        type="primary" 
        size="large"
        :loading="isProcessingQueue"
        @click="$emit('processQueue')"
        class="shadow-lg"
      >
        <template #icon>
          <sync-outlined />
        </template>
        Sync Pending
      </a-button>
    </a-badge>
  </div>
</template>

<script setup>
import { SyncOutlined } from '@ant-design/icons-vue';

defineProps({
  isOffline: Boolean,
  offlineQueue: Array,
  isProcessingQueue: Boolean,
});

defineEmits(['processQueue']);
</script>
