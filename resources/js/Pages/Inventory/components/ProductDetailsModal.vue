<script setup>
import { computed, toRefs } from "vue";
import { 
  IconCircleCheck, 
  IconAlertTriangle, 
  IconCircleX,
  IconBuildingStore,
  IconTag,
  IconCurrencyDollar,
  IconCalendar
} from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";

const { formatCurrency, formatDate, formatDateTime } = useHelpers();

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  product: {
    type: Object,
    default: null,
  },
});

const { visible } = toRefs(props);

const emit = defineEmits(['update:visible']);

const handleClose = () => {
  emit('update:visible', false);
};

const getStockStatusColor = (status) => {
  switch (status) {
    case 'in_stock': return 'success';
    case 'low_stock': return 'warning';
    case 'out_of_stock': return 'error';
    default: return 'default';
  }
};

const getStockStatusIcon = (status) => {
  switch (status) {
    case 'in_stock': return IconCircleCheck;
    case 'low_stock': return IconAlertTriangle;
    case 'out_of_stock': return IconCircleX;
    default: return IconCircleCheck;
  }
};

const getStockStatusText = (status) => {
  switch (status) {
    case 'in_stock': return 'In Stock';
    case 'low_stock': return 'Low Stock';
    case 'out_of_stock': return 'Out of Stock';
    default: return 'Unknown';
  }
};
</script>

<template>
  <a-modal
    v-model:visible="visible"
    title="Product Details"
    width="800px"
    @cancel="handleClose"
    :footer="null"
  >
    <div v-if="product" class="space-y-6">
      <!-- Product Header -->
      <div class="flex items-start space-x-4 pb-4 border-b">
        <!-- Product Image/Avatar -->
        <div class="w-16 h-16 bg-gray-200 rounded-lg flex items-center justify-center flex-shrink-0">
          <img 
            v-if="product.product?.representation_type === 'image' && product.product?.representation"
            :src="product.product.representation"
            :alt="product.product.name"
            class="w-full h-full object-cover rounded-lg"
          />
          <div 
            v-else-if="product.product?.representation_type === 'color' && product.product?.representation"
            class="w-full h-full rounded-lg"
            :style="{ backgroundColor: `#${product.product.representation}` }"
          ></div>
          <span v-else class="text-xl text-gray-500">
            {{ product.product?.name?.charAt(0) || 'P' }}
          </span>
        </div>
        
        <!-- Product Info -->
        <div class="flex-1">
          <h3 class="text-xl font-semibold text-gray-900">{{ product.product?.name || 'Unknown Product' }}</h3>
          <p class="text-gray-600">{{ product.product?.category?.name || 'No Category' }}</p>
          <p class="text-sm text-gray-500">SKU: {{ product.product?.SKU || 'N/A' }}</p>
        </div>
        
        <!-- Status Badge -->
        <a-tag :color="getStockStatusColor(product.product?.stock_status)" size="large">
          <component :is="getStockStatusIcon(product.product?.stock_status)" :size="16" class="mr-1" />
          {{ getStockStatusText(product.product?.stock_status) }}
        </a-tag>
      </div>

      <!-- Stock Information -->
      <div>
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconBuildingStore :size="20" class="mr-2" />
          Stock Information
        </h4>
        <a-row :gutter="16">
          <a-col :span="8">
            <a-card size="small">
              <div class="text-center">
                <p class="text-2xl font-bold text-blue-600">{{ product.quantity_on_hand }}</p>
                <p class="text-sm text-gray-500">On Hand</p>
                <p class="text-xs text-gray-400">{{ product.product?.unit_of_measure || 'pcs' }}</p>
              </div>
            </a-card>
          </a-col>
          <a-col :span="8">
            <a-card size="small">
              <div class="text-center">
                <p class="text-2xl font-bold" :class="{
                  'text-green-600': product.quantity_available > 0,
                  'text-red-600': product.quantity_available <= 0
                }">{{ product.quantity_available }}</p>
                <p class="text-sm text-gray-500">Available</p>
                <p class="text-xs text-gray-400">{{ product.product?.unit_of_measure || 'pcs' }}</p>
              </div>
            </a-card>
          </a-col>
          <a-col :span="8">
            <a-card size="small">
              <div class="text-center">
                <p class="text-2xl font-bold" :class="{
                  'text-orange-600': product.quantity_reserved > 0,
                  'text-gray-500': product.quantity_reserved <= 0
                }">{{ product.quantity_reserved }}</p>
                <p class="text-sm text-gray-500">Reserved</p>
                <p class="text-xs text-gray-400">{{ product.product?.unit_of_measure || 'pcs' }}</p>
              </div>
            </a-card>
          </a-col>
        </a-row>
      </div>

      <!-- Financial Information -->
      <div>
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconCurrencyDollar :size="20" class="mr-2" />
          Financial Information
        </h4>
        <a-row :gutter="16">
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm text-gray-600">Total Value</p>
              <p class="text-lg font-semibold">{{ formatCurrency(product.total_value) }}</p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm text-gray-600">Average Cost</p>
              <p class="text-lg font-semibold">{{ formatCurrency(product.average_cost) }}</p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm text-gray-600">Last Cost</p>
              <p class="text-lg font-semibold">{{ formatCurrency(product.last_cost) }}</p>
            </div>
          </a-col>
        </a-row>
      </div>

      <!-- Product Details -->
      <div>
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconTag :size="20" class="mr-2" />
          Product Details
        </h4>
        <a-row :gutter="16">
          <a-col :span="12">
            <div class="space-y-2">
              <div class="flex justify-between">
                <span class="text-gray-600">Selling Price:</span>
                <span class="font-semibold">{{ formatCurrency(product.product?.price) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Cost Price:</span>
                <span class="font-semibold">{{ formatCurrency(product.product?.cost) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Reorder Level:</span>
                <span class="font-semibold">{{ product.product?.reorder_level || 'Not set' }}</span>
              </div>
            </div>
          </a-col>
          <a-col :span="12">
            <div class="space-y-2">
              <div class="flex justify-between">
                <span class="text-gray-600">Max Stock Level:</span>
                <span class="font-semibold">{{ product.product?.max_stock_level || 'Not set' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Unit Weight:</span>
                <span class="font-semibold">{{ product.product?.unit_weight || 'Not set' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Supplier SKU:</span>
                <span class="font-semibold">{{ product.product?.supplier_sku || 'Not set' }}</span>
              </div>
            </div>
          </a-col>
        </a-row>
      </div>

      <!-- Movement History -->
      <div>
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconCalendar :size="20" class="mr-2" />
          Recent Activity
        </h4>
        <a-row :gutter="16">
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm text-gray-600">Last Movement</p>
              <p class="text-sm font-semibold">{{ formatDateTime(product.last_movement_at) }}</p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm text-gray-600">Last Restock</p>
              <p class="text-sm font-semibold">{{ formatDateTime(product.last_restock_at) }}</p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm text-gray-600">Last Sale</p>
              <p class="text-sm font-semibold">{{ formatDateTime(product.last_sale_at) }}</p>
            </div>
          </a-col>
        </a-row>
      </div>

      <!-- Notes -->
      <div v-if="product.product?.notes">
        <h4 class="text-lg font-semibold mb-3">Notes</h4>
        <div class="bg-gray-50 p-3 rounded">
          <p class="text-sm">{{ product.product.notes }}</p>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-8">
      <p class="text-gray-500">No product selected</p>
    </div>
  </a-modal>
</template>
