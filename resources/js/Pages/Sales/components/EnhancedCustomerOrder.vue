<template>
  <div class="relative">
    <!-- Enhanced Transaction Controls -->
    <TransactionControls
      :orders="orders"
      :held-transactions="heldTransactions"
      :last-activity="lastActivity"
      :is-offline="isOffline"
      @hold-transaction="handleHoldTransaction"
      @show-held-transactions="showHeldTransactionsModal = true"
      class="mb-4"
    />

    <!-- Original Customer Order Content -->
    <div class="space-y-4">
      <!-- Your existing order items display here -->
      <div v-for="order in orders" :key="order.id" class="order-item">
        <!-- Existing order item template -->
      </div>
    </div>

    <!-- Offline Indicator -->
    <OfflineIndicator
      :is-offline="isOffline"
      :offline-queue="offlineQueue"
      :is-processing-queue="offline.isProcessingQueue"
      @process-queue="offline.processOfflineQueue"
    />

    <!-- Held Transactions Modal -->
    <HeldTransactionsModal
      :visible="showHeldTransactionsModal"
      :held-transactions="heldTransactions"
      :format-transaction-for-display="hold.formatTransactionForDisplay"
      @close="showHeldTransactionsModal = false"
      @recall="handleRecallTransaction"
      @clear="hold.clearHeldTransaction"
      @clear-all="hold.clearAllHeldTransactions"
    />

    <!-- Existing Modals -->
    <!-- Your existing void modal, discount modals, etc. -->
  </div>
</template>

<script setup>
import { ref } from "vue";
// Removed useOrderV2 import - using direct props instead
import TransactionControls from "@/Components/POS/TransactionControls.vue";
import OfflineIndicator from "@/Components/POS/OfflineIndicator.vue";
import HeldTransactionsModal from "@/Components/POS/HeldTransactionsModal.vue";

// Enhanced useOrders with new features - DISABLED (useOrderV2 removed)
// TODO: Refactor to use direct props and API calls
const orders = ref([]);
const orderId = ref(null);
const orderDiscountAmount = ref(0);
const orderDiscountId = ref('');
const totalAmount = ref(0);
const formattedTotal = ref('₱0.00');
const isOffline = ref(false);
const offlineQueue = ref([]);
const heldTransactions = ref([]);
const lastActivity = ref(null);

// Modal states
const showHeldTransactionsModal = ref(false);

// Enhanced handlers
const handleHoldTransaction = () => {
  holdCurrentTransaction();
};

const handleRecallTransaction = (transactionId) => {
  recallHeldTransaction(transactionId);
  showHeldTransactionsModal.value = false;
};

// Your existing handlers and logic...
</script>

<style scoped>
/* Your existing styles */
</style>
