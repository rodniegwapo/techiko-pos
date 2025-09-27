<template>
  <a-modal
    :visible="visible"
    title="Held Transactions"
    width="900px"
    :footer="null"
    @cancel="$emit('close')"
  >
    <div class="mb-4 flex justify-between items-center">
      <div class="text-sm text-gray-600">
        {{ heldTransactions.length }} held transaction(s)
      </div>
      <a-button 
        v-if="heldTransactions.length > 0"
        danger 
        size="small"
        @click="clearAllTransactions"
      >
        Clear All
      </a-button>
    </div>

    <div v-if="heldTransactions.length === 0" class="text-center py-8">
      <a-empty description="No held transactions">
        <template #image>
          <pause-outlined style="font-size: 48px; color: #d9d9d9;" />
        </template>
      </a-empty>
    </div>
    
    <div v-else class="space-y-4 max-h-96 overflow-y-auto">
      <div
        v-for="transaction in formattedTransactions"
        :key="transaction.id"
        class="border rounded-lg p-4 hover:bg-gray-50 transition-colors"
      >
        <div class="flex justify-between items-start mb-3">
          <div>
            <h3 class="font-semibold text-lg flex items-center gap-2">
              <pause-outlined class="text-orange-500" />
              Transaction #{{ transaction.displayId }}
            </h3>
            <p class="text-sm text-gray-600">
              <user-outlined class="mr-1" />
              Held by: {{ transaction.cashier }}
            </p>
            <p class="text-sm text-gray-600">
              <clock-circle-outlined class="mr-1" />
              {{ transaction.formattedTimestamp }}
            </p>
          </div>
          <div class="text-right">
            <p class="text-lg font-bold text-green-600">
              {{ formattedTotal(transaction.total) }}
            </p>
            <p class="text-sm text-gray-600">
              <shopping-cart-outlined class="mr-1" />
              {{ transaction.itemCount }} items
            </p>
          </div>
        </div>
        
        <div class="mb-3">
          <a-collapse size="small" ghost>
            <a-collapse-panel key="items" header="View Items">
              <div class="space-y-1 max-h-32 overflow-y-auto">
                <div
                  v-for="item in transaction.orders"
                  :key="item.id"
                  class="flex justify-between text-sm py-1 border-b border-gray-100 last:border-0"
                >
                  <span class="flex-1">{{ item.name }}</span>
                  <span class="text-gray-600 mx-2">x{{ item.quantity }}</span>
                  <span class="font-medium">{{ formattedTotal(item.subtotal || item.price * item.quantity) }}</span>
                </div>
              </div>
            </a-collapse-panel>
          </a-collapse>
        </div>
        
        <div class="flex justify-end space-x-2">
          <a-button
            type="primary"
            @click="recallTransaction(transaction.id)"
          >
            <template #icon>
              <redo-outlined />
            </template>
            Recall
          </a-button>
          <a-button
            danger
            @click="clearTransaction(transaction.id)"
          >
            <template #icon>
              <delete-outlined />
            </template>
            Delete
          </a-button>
        </div>
      </div>
    </div>
  </a-modal>
</template>

<script setup>
import { computed } from 'vue';
import { 
  PauseOutlined, 
  UserOutlined, 
  ClockCircleOutlined, 
  ShoppingCartOutlined,
  RedoOutlined,
  DeleteOutlined
} from '@ant-design/icons-vue';
import { Modal } from 'ant-design-vue';

const props = defineProps({
  visible: Boolean,
  heldTransactions: Array,
  formatTransactionForDisplay: Function,
});

const emit = defineEmits(['close', 'recall', 'clear', 'clearAll']);

const formattedTotal = (total) =>
  new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(total);

const formattedTransactions = computed(() => {
  return props.heldTransactions.map(props.formatTransactionForDisplay);
});

const recallTransaction = (transactionId) => {
  emit('recall', transactionId);
  emit('close');
};

const clearTransaction = (transactionId) => {
  Modal.confirm({
    title: 'Clear Transaction',
    content: 'Are you sure you want to delete this held transaction? This action cannot be undone.',
    okText: 'Yes, Delete',
    okType: 'danger',
    cancelText: 'Cancel',
    onOk() {
      emit('clear', transactionId);
    },
  });
};

const clearAllTransactions = () => {
  Modal.confirm({
    title: 'Clear All Transactions',
    content: 'Are you sure you want to delete all held transactions? This action cannot be undone.',
    okText: 'Yes, Delete All',
    okType: 'danger',
    cancelText: 'Cancel',
    onOk() {
      emit('clearAll');
    },
  });
};
</script>
