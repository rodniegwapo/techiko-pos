<script setup>
import { computed, toRefs } from "vue";
import { 
  IconClipboardList,
  IconMapPin,
  IconUser,
  IconCalendar,
  IconFileText,
  IconCurrencyDollar,
  IconCheck,
  IconX,
  IconClock
} from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";

const { formatCurrency, formatDate, formatDateTime } = useHelpers();

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  adjustment: {
    type: Object,
    default: null,
  },
});

const { visible } = toRefs(props);

const emit = defineEmits(['update:visible', 'refresh']);

const handleClose = () => {
  emit('update:visible', false);
};

const getStatusColor = (status) => {
  const colors = {
    'draft': 'blue',
    'pending_approval': 'orange',
    'approved': 'green',
    'rejected': 'red',
    'completed': 'purple',
  };
  return colors[status] || 'default';
};

const getStatusDisplay = (status) => {
  const displays = {
    'draft': 'Draft',
    'pending_approval': 'Pending Approval',
    'approved': 'Approved',
    'rejected': 'Rejected',
    'completed': 'Completed',
  };
  return displays[status] || status?.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const getStatusIcon = (status) => {
  const icons = {
    'draft': IconFileText,
    'pending_approval': IconClock,
    'approved': IconCheck,
    'rejected': IconX,
    'completed': IconCheck,
  };
  return icons[status] || IconFileText;
};

const totalValueAdjusted = computed(() => {
  if (!props.adjustment?.items) return 0;
  return props.adjustment.items.reduce((sum, item) => {
    return sum + (item.total_cost_change || 0);
  }, 0);
});

const totalQuantityAdjusted = computed(() => {
  if (!props.adjustment?.items) return 0;
  return props.adjustment.items.reduce((sum, item) => {
    return sum + Math.abs(item.adjustment_quantity || 0);
  }, 0);
});
</script>

<template>
  <a-modal
    v-model:visible="visible"
    title="Stock Adjustment Details"
    width="900px"
    @cancel="handleClose"
    :footer="null"
  >
    <div v-if="adjustment" class="space-y-6">
      <!-- Adjustment Header -->
      <div class="flex items-start justify-between pb-4 border-b">
        <div class="flex items-center space-x-3">
          <div class="p-2 rounded-lg bg-blue-100 text-blue-600">
            <IconClipboardList :size="24" />
          </div>
          <div>
            <h3 class="text-lg font-semibold">Stock Adjustment #{{ adjustment.id }}</h3>
            <p class="text-sm text-gray-500">{{ formatDate(adjustment.adjustment_date) }}</p>
          </div>
        </div>
        
        <a-tag :color="getStatusColor(adjustment.status)" size="large">
          <component :is="getStatusIcon(adjustment.status)" :size="16" class="mr-1" />
          {{ getStatusDisplay(adjustment.status) }}
        </a-tag>
      </div>

      <!-- Summary Information -->
      <div class="grid grid-cols-3 gap-4">
        <div class="bg-blue-50 p-4 rounded-lg text-center">
          <p class="text-2xl font-bold text-blue-600">{{ adjustment.items?.length || 0 }}</p>
          <p class="text-sm text-gray-600">Products Adjusted</p>
        </div>
        <div class="bg-orange-50 p-4 rounded-lg text-center">
          <p class="text-2xl font-bold text-orange-600">{{ totalQuantityAdjusted }}</p>
          <p class="text-sm text-gray-600">Total Quantity</p>
        </div>
        <div class="bg-green-50 p-4 rounded-lg text-center">
          <p class="text-2xl font-bold text-green-600">{{ formatCurrency(totalValueAdjusted) }}</p>
          <p class="text-sm text-gray-600">Value Impact</p>
        </div>
      </div>

      <!-- Basic Information -->
      <div class="grid grid-cols-2 gap-6">
        <!-- Location & User -->
        <div>
          <h4 class="text-md font-semibold mb-3 flex items-center">
            <IconMapPin :size="18" class="mr-2" />
            Location & User
          </h4>
          <div class="space-y-2">
            <div class="flex justify-between">
              <span class="text-gray-600">Location:</span>
              <span class="font-semibold">{{ adjustment.location?.name || 'Unknown' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Created By:</span>
              <span class="font-semibold">{{ adjustment.user?.name || 'System' }}</span>
            </div>
            <div v-if="adjustment.approved_by_user" class="flex justify-between">
              <span class="text-gray-600">Approved By:</span>
              <span class="font-semibold">{{ adjustment.approved_by_user.name }}</span>
            </div>
          </div>
        </div>

        <!-- Dates -->
        <div>
          <h4 class="text-md font-semibold mb-3 flex items-center">
            <IconCalendar :size="18" class="mr-2" />
            Important Dates
          </h4>
          <div class="space-y-2">
            <div class="flex justify-between">
              <span class="text-gray-600">Adjustment Date:</span>
              <span class="font-semibold">{{ formatDate(adjustment.adjustment_date) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Created:</span>
              <span class="font-semibold">{{ formatDateTime(adjustment.created_at) }}</span>
            </div>
            <div v-if="adjustment.updated_at !== adjustment.created_at" class="flex justify-between">
              <span class="text-gray-600">Last Updated:</span>
              <span class="font-semibold">{{ formatDateTime(adjustment.updated_at) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Reason & Notes -->
      <div v-if="adjustment.reason || adjustment.notes">
        <h4 class="text-md font-semibold mb-3 flex items-center">
          <IconFileText :size="18" class="mr-2" />
          Reason & Notes
        </h4>
        <div class="bg-gray-50 p-4 rounded-lg space-y-2">
          <div v-if="adjustment.reason">
            <p class="text-sm text-gray-600">Reason:</p>
            <p class="font-semibold">{{ adjustment.reason }}</p>
          </div>
          <div v-if="adjustment.notes">
            <p class="text-sm text-gray-600">Notes:</p>
            <p>{{ adjustment.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Adjustment Items -->
      <div v-if="adjustment.items?.length > 0">
        <h4 class="text-md font-semibold mb-3">Adjustment Items</h4>
        <div class="border border-gray-200 rounded-lg overflow-hidden">
          <div class="bg-gray-50 px-4 py-2 grid grid-cols-12 gap-2 text-sm font-medium text-gray-700">
            <div class="col-span-4">Product</div>
            <div class="col-span-2 text-center">System Qty</div>
            <div class="col-span-2 text-center">Actual Qty</div>
            <div class="col-span-2 text-center">Adjustment</div>
            <div class="col-span-2 text-right">Cost Impact</div>
          </div>

          <div
            v-for="(item, index) in adjustment.items"
            :key="index"
            class="px-4 py-3 grid grid-cols-12 gap-2 items-center border-b border-gray-100 last:border-b-0"
          >
            <!-- Product Info -->
            <div class="col-span-4">
              <p class="font-medium text-sm">{{ item.product?.name || 'Unknown Product' }}</p>
              <p class="text-xs text-gray-500">{{ item.product?.SKU || 'N/A' }}</p>
            </div>

            <!-- System Quantity -->
            <div class="col-span-2 text-center">
              <p class="font-semibold">{{ item.system_quantity }}</p>
            </div>

            <!-- Actual Quantity -->
            <div class="col-span-2 text-center">
              <p class="font-semibold">{{ item.actual_quantity }}</p>
            </div>

            <!-- Adjustment -->
            <div class="col-span-2 text-center">
              <p class="font-semibold" :class="{
                'text-green-600': item.adjustment_quantity > 0,
                'text-red-600': item.adjustment_quantity < 0,
                'text-gray-600': item.adjustment_quantity === 0
              }">
                {{ item.adjustment_quantity > 0 ? '+' : '' }}{{ item.adjustment_quantity }}
              </p>
            </div>

            <!-- Cost Impact -->
            <div class="col-span-2 text-right">
              <p class="font-semibold" :class="{
                'text-green-600': item.total_cost_change > 0,
                'text-red-600': item.total_cost_change < 0,
                'text-gray-600': item.total_cost_change === 0
              }">
                {{ formatCurrency(item.total_cost_change || 0) }}
              </p>
            </div>
          </div>

          <!-- Total Row -->
          <div class="bg-gray-50 px-4 py-3 grid grid-cols-12 gap-2 items-center font-semibold">
            <div class="col-span-8">Total Impact</div>
            <div class="col-span-2 text-center">{{ totalQuantityAdjusted }} items</div>
            <div class="col-span-2 text-right">{{ formatCurrency(totalValueAdjusted) }}</div>
          </div>
        </div>
      </div>

      <!-- Action Buttons (if applicable) -->
      <div v-if="adjustment.status === 'pending_approval'" class="flex justify-end space-x-2 pt-4 border-t">
        <a-button type="default" @click="handleClose">
          Close
        </a-button>
        <a-button type="danger">
          Reject
        </a-button>
        <a-button type="primary">
          Approve
        </a-button>
      </div>
    </div>

    <div v-else class="text-center py-8">
      <p class="text-gray-500">No adjustment selected</p>
    </div>
  </a-modal>
</template>
