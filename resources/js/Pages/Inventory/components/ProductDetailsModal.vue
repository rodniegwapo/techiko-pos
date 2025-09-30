<script setup>
import { computed, toRefs, ref, watch } from "vue";
import {
  IconCircleCheck,
  IconAlertTriangle,
  IconCircleX,
  IconBuildingStore,
  IconTag,
  IconCurrencyDollar,
  IconCalendar,
  IconShoppingCart,
  IconAlertOctagon,
} from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import axios from "axios";

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

const emit = defineEmits(["update:visible"]);

// Store summary state
const storeData = ref(null);
const storeLoading = ref(false);

// Load store summary when product changes
const loadStoreData = async () => {
  if (!props.product?.location_id) return;
  
  storeLoading.value = true;
  try {
    const response = await axios.get(`/api/inventory/locations/${props.product.location_id}/summary`);
    storeData.value = response.data;
  } catch (error) {
    console.error('Failed to load store data:', error);
    storeData.value = null;
  } finally {
    storeLoading.value = false;
  }
};

// Watch for product changes
watch(
  () => props.product,
  (newProduct) => {
    if (newProduct && props.visible) {
      loadStoreData();
    }
  },
  { immediate: true }
);

// Watch for modal visibility
watch(
  () => props.visible,
  (isVisible) => {
    if (isVisible && props.product) {
      loadStoreData();
    }
  }
);

const handleClose = () => {
  emit("update:visible", false);
};

const getStockStatusColor = (status) => {
  switch (status) {
    case "in_stock":
      return "success";
    case "low_stock":
      return "warning";
    case "out_of_stock":
      return "error";
    default:
      return "default";
  }
};

const getStockStatusIcon = (status) => {
  switch (status) {
    case "in_stock":
      return IconCircleCheck;
    case "low_stock":
      return IconAlertTriangle;
    case "out_of_stock":
      return IconCircleX;
    default:
      return IconCircleCheck;
  }
};

const getStockStatusText = (status) => {
  switch (status) {
    case "in_stock":
      return "In Stock";
    case "low_stock":
      return "Low Stock";
    case "out_of_stock":
      return "Out of Stock";
    default:
      return "Unknown";
  }
};
</script>

<template>
  <a-modal
    v-model:visible="visible"
    width="900px"
    @cancel="handleClose"
    :footer="null"
  >
    <template #title>
      <div class="flex items-center justify-between">
        <span>Product Details</span>
        <div v-if="storeData" class="flex items-center space-x-2">
          <a-tag color="blue" size="small">
            <IconShoppingCart :size="14" class="mr-1" />
            {{ storeData.total_products_count }} total items
          </a-tag>
          <a-tag v-if="storeData.low_stock_products_count > 0" color="orange" size="small">
            <IconAlertTriangle :size="14" class="mr-1" />
            {{ storeData.low_stock_products_count }} low stock
          </a-tag>
          <a-tag v-if="storeData.out_of_stock_products_count > 0" color="red" size="small">
            <IconAlertOctagon :size="14" class="mr-1" />
            {{ storeData.out_of_stock_products_count }} out of stock
          </a-tag>
        </div>
      </div>
    </template>
    <div v-if="product" class="space-y-6">
      <!-- Store Context Banner -->
      <div v-if="storeData" class="bg-gradient-to-r from-blue-50 to-purple-50 p-4 rounded-lg border border-blue-200">
        <div class="flex items-center justify-between">
          <div>
            <h5 class="font-semibold text-blue-900">{{ storeData.name }}</h5>
            <p class="text-sm text-blue-700">{{ storeData.address }}</p>
          </div>
          <div class="grid grid-cols-4 gap-4 text-center">
            <div>
              <p class="text-lg font-bold text-blue-600">{{ storeData.total_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">Total Items</p>
            </div>
            <div>
              <p class="text-lg font-bold text-green-600">{{ storeData.in_stock_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">In Stock</p>
            </div>
            <div>
              <p class="text-lg font-bold text-yellow-600">{{ storeData.low_stock_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">Low Stock</p>
            </div>
            <div>
              <p class="text-lg font-bold text-red-600">{{ storeData.out_of_stock_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">Out of Stock</p>
            </div>
          </div>
        </div>
        <div class="mt-3 pt-3 border-t border-blue-200">
          <div class="text-center">
            <p class="text-sm text-blue-700">Total Store Inventory Value</p>
            <p class="text-xl font-bold text-purple-600">
              ₱{{ (storeData.total_inventory_value || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
            </p>
          </div>
        </div>
      </div>

      <!-- Product Header -->
      <div class="flex items-start space-x-4 pb-4 border-b">
        <!-- Product Image/Avatar -->
        <div
          class="w-16 h-16 bg-gray-200 rounded-lg flex items-center justify-center flex-shrink-0"
        >
          <img
            v-if="
              product.product?.representation_type === 'image' &&
              product.product?.representation
            "
            :src="product.product.representation"
            :alt="product.product.name"
            class="w-full h-full object-cover rounded-lg"
          />
          <div
            v-else-if="
              product.product?.representation_type === 'color' &&
              product.product?.representation
            "
            class="w-full h-full rounded-lg"
            :style="{ backgroundColor: `#${product.product.representation}` }"
          ></div>
          <span v-else class="text-xl text-gray-500">
            {{ product.product?.name?.charAt(0) || "P" }}
          </span>
        </div>

        <!-- Product Info -->
        <div class="flex-1">
          <h3 class="text-xl font-semibold text-gray-900">
            {{ product.product?.name || "Unknown Product" }}
          </h3>
          <p class="text-gray-600">
            {{ product.product?.category?.name || "No Category" }}
          </p>
          <p class="text-sm text-gray-500">
            SKU: {{ product.product?.SKU || "N/A" }}
          </p>
        </div>

        <!-- Status Badge -->
        <a-tag
          :color="getStockStatusColor(product.product?.stock_status)"
          size="large"
        >
          <component
            :is="getStockStatusIcon(product.product?.stock_status)"
            :size="16"
            class="mr-1"
          />
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
            <div class="bg-gray-50 p-3 rounded-lg border">
              <div class="text-center">
                <p class="text-2xl font-bold text-blue-600">
                  {{ product.quantity_on_hand }}
                </p>
                <p class="text-sm text-gray-500">On Hand</p>
                <p class="text-xs text-gray-400">
                  {{ product.product?.unit_of_measure || "pcs" }}
                </p>
              </div>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded-lg border">
              <div class="text-center">
                <p
                  class="text-2xl font-bold"
                  :class="{
                    'text-green-600': product.quantity_available > 0,
                    'text-red-600': product.quantity_available <= 0,
                  }"
                >
                  {{ product.quantity_available }}
                </p>
                <p class="text-sm text-gray-500">Available</p>
                <p class="text-xs text-gray-400">
                  {{ product.product?.unit_of_measure || "pcs" }}
                </p>
              </div>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded-lg border">
              <div class="text-center rounded-lg">
                <p
                  class="text-2xl font-bold"
                  :class="{
                    'text-orange-600': product.quantity_reserved > 0,
                    'text-gray-500': product.quantity_reserved <= 0,
                  }"
                >
                  {{ product.quantity_reserved }}
                </p>
                <p class="text-sm text-gray-500">Reserved</p>
                <p class="text-xs text-gray-400">
                  {{ product.product?.unit_of_measure || "pcs" }}
                </p>
              </div>
            </div>
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
            <div class="bg-gray-100 p-3 rounded-lg border">
              <p class="text-sm text-gray-600">Total Value</p>
              <p class="text-lg font-semibold">
                {{ formatCurrency(product.total_value) }}
              </p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-100 p-3 rounded-lg border">
              <p class="text-sm text-gray-600">Average Cost</p>
              <p class="text-lg font-semibold">
                {{ formatCurrency(product.average_cost) }}
              </p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-100 p-3 rounded border">
              <p class="text-sm text-gray-600">Last Cost</p>
              <p class="text-lg font-semibold">
                {{ formatCurrency(product.last_cost) }}
              </p>
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
                <span class="font-semibold">{{
                  formatCurrency(product.product?.price)
                }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Cost Price:</span>
                <span class="font-semibold">{{
                  formatCurrency(product.product?.cost)
                }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Reorder Level:</span>
                <span class="font-semibold">{{
                  product.product?.reorder_level || "Not set"
                }}</span>
              </div>
            </div>
          </a-col>
          <a-col :span="12">
            <div class="space-y-2">
              <div class="flex justify-between">
                <span class="text-gray-600">Max Stock Level:</span>
                <span class="font-semibold">{{
                  product.product?.max_stock_level || "Not set"
                }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Unit Weight:</span>
                <span class="font-semibold">{{
                  product.product?.unit_weight || "Not set"
                }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Supplier SKU:</span>
                <span class="font-semibold">{{
                  product.product?.supplier_sku || "Not set"
                }}</span>
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
            <div class="bg-gray-50 p-3 rounded-lg border">
              <p class="text-sm text-gray-600">Last Movement</p>
              <p class="text-sm font-semibold">
                {{ formatDateTime(product.last_movement_at) }}
              </p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded-lg border">
              <p class="text-sm text-gray-600">Last Restock</p>
              <p class="text-sm font-semibold">
                {{ formatDateTime(product.last_restock_at) }}
              </p>
            </div>
          </a-col>
          <a-col :span="8">
            <div class="bg-gray-50 p-3 rounded-lg border">
              <p class="text-sm text-gray-600">Last Sale</p>
              <p class="text-sm font-semibold">
                {{ formatDateTime(product.last_sale_at) }}
              </p>
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
