<script setup>
import { computed, toRefs, ref, watch } from "vue";
import { useMediaQuery } from "@vueuse/core";
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
const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() =>
  isMdUp.value ? 900 : "calc(100vw - 24px)",
);
const modalRootStyle = computed(() =>
  isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);
const modalBodyStyle = computed(() =>
  isMdUp.value ? {} : { maxHeight: "calc(100vh - 120px)", overflowY: "auto" },
);

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  product: {
    type: Object,
    default: null,
  },
  // When true, we're in global view and should render locations list using eager-loaded data
  isGlobalView: {
    type: Boolean,
    default: false,
  },
});

const { visible } = toRefs(props);

const emit = defineEmits(["update:visible"]);

// Store summary state
const storeData = ref(null);
const storeLoading = ref(false);

// Load store summary when product changes (domain-scoped view only)
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
    if (newProduct && props.visible && !props.isGlobalView) {
      loadStoreData();
    }
  },
  { immediate: true }
);

// Watch for modal visibility
watch(
  () => props.visible,
  (isVisible) => {
    if (isVisible && props.product && !props.isGlobalView) {
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

// Eager-loaded locations for global view (from product.product.locations)
const allLocations = computed(() => props.product?.product?.locations || []);
</script>

<template>
  <a-modal
    v-model:visible="visible"
    :width="modalWidth"
    :style="modalRootStyle"
    :body-style="modalBodyStyle"
    centered
    @cancel="handleClose"
    :footer="null"
  >
    <template #title>
      <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <span>Product Details</span>
        <div v-if="!props.isGlobalView && storeData" class="flex flex-wrap items-center gap-2">
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
        <div v-else-if="props.isGlobalView && allLocations.length" class="flex flex-wrap items-center gap-2">
          <a-tag color="blue" size="small">
            {{ allLocations.length }} locations
          </a-tag>
        </div>
      </div>
    </template>
    <div v-if="product" class="space-y-6">
      <!-- Store Context Banner (domain-scoped) -->
      <div v-if="!props.isGlobalView && storeData" class="bg-gradient-to-r from-blue-50 to-purple-50 p-4 rounded-lg border border-blue-200">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h5 class="font-semibold text-blue-900">{{ storeData.name }}</h5>
            <p class="text-sm text-blue-700">{{ storeData.address }}</p>
          </div>
          <div class="grid grid-cols-2 gap-4 text-center md:grid-cols-4">
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
      <div class="flex flex-col gap-4 border-b pb-4 md:flex-row md:items-start md:gap-4">
        <!-- Product Image/Avatar -->
        <div
          class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-gray-200"
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
        <div class="min-w-0 flex-1">
          <h3 class="break-words text-xl font-semibold text-gray-900">
            {{ product.product?.name || "Unknown Product" }}
          </h3>
          <p class="text-gray-600">
            {{ product.product?.category?.name || "No Category" }}
          </p>
          <p class="text-sm text-gray-500">
            SKU: {{ product.product?.SKU || "N/A" }}
          </p>
          <!-- Domain and Location Info -->
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <a-tag color="blue" size="small">
              Domain: {{ product.product?.domain || "N/A" }}
            </a-tag>
            <a-tag v-if="!props.isGlobalView && product.location" color="green" size="small">
              Location: {{ product.location?.name || "N/A" }}
            </a-tag>
            <a-tag v-else-if="props.isGlobalView" color="purple" size="small">
              {{ allLocations.length }} locations
            </a-tag>
          </div>
        </div>

        <!-- Status Badge -->
        <a-tag
          class="w-fit self-start"
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

      <!-- Stock Information (single location only) -->
      <div v-if="!props.isGlobalView">
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconBuildingStore :size="20" class="mr-2" />
          Stock Information
        </h4>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div class="rounded-lg border bg-gray-50 p-3">
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
          <div class="rounded-lg border bg-gray-50 p-3">
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
          <div class="rounded-lg border bg-gray-50 p-3">
            <div class="rounded-lg text-center">
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
        </div>
      </div>

      <!-- Global View: Locations Table -->
      <div v-if="props.isGlobalView">
        <h4 class="text-lg font-semibold mb-3">Stock Across All Locations</h4>
        <div v-if="!allLocations.length" class="text-center text-gray-500 py-6">
          No location data available
        </div>
        <div v-else-if="isMdUp" class="overflow-x-auto">
          <a-table :data-source="allLocations" :pagination="false" size="small">
            <a-table-column title="Location" key="name">
              <template #default="{ record }">
                <div class="font-semibold">{{ record.name }}</div>
                <div class="text-xs text-gray-500">{{ record.address }}</div>
              </template>
            </a-table-column>
            <a-table-column title="Status" key="status" align="center">
              <template #default>
                <a-tag color="blue" size="small">Active</a-tag>
              </template>
            </a-table-column>
          </a-table>
        </div>
        <div v-else class="flex flex-col gap-3">
          <div
            v-for="location in allLocations"
            :key="location.id"
            class="overflow-hidden rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
          >
            <div class="truncate font-semibold text-gray-900">
              {{ location.name }}
            </div>
            <div class="mt-1 break-words text-xs text-gray-500">
              {{ location.address }}
            </div>
            <div class="mt-2">
              <a-tag color="blue" size="small">Active</a-tag>
            </div>
          </div>
        </div>
      </div>

      <!-- Financial Information -->
      <div>
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconCurrencyDollar :size="20" class="mr-2" />
          Financial Information
        </h4>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div class="rounded-lg border bg-gray-100 p-3">
            <p class="text-sm text-gray-600">Total Value</p>
            <p class="text-lg font-semibold">
              {{ formatCurrency(product.total_value) }}
            </p>
          </div>
          <div class="rounded-lg border bg-gray-100 p-3">
            <p class="text-sm text-gray-600">Average Cost</p>
            <p class="text-lg font-semibold">
              {{ formatCurrency(product.average_cost) }}
            </p>
          </div>
          <div class="rounded border bg-gray-100 p-3">
            <p class="text-sm text-gray-600">Last Cost</p>
            <p class="text-lg font-semibold">
              {{ formatCurrency(product.last_cost) }}
            </p>
          </div>
        </div>
      </div>

      <!-- Product Details -->
      <div>
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconTag :size="20" class="mr-2" />
          Product Details
        </h4>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div class="space-y-2">
            <div class="flex justify-between gap-2">
              <span class="text-gray-600">Selling Price:</span>
              <span class="font-semibold">{{
                formatCurrency(product.product?.price)
              }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span class="text-gray-600">Cost Price:</span>
              <span class="font-semibold">{{
                formatCurrency(product.product?.cost)
              }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span class="text-gray-600">Reorder Level:</span>
              <span class="font-semibold">{{
                product.product?.reorder_level || "Not set"
              }}</span>
            </div>
          </div>
          <div class="space-y-2">
            <div class="flex justify-between gap-2">
              <span class="text-gray-600">Max Stock Level:</span>
              <span class="font-semibold">{{
                product.product?.max_stock_level || "Not set"
              }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span class="text-gray-600">Unit Weight:</span>
              <span class="font-semibold">{{
                product.product?.unit_weight || "Not set"
              }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span class="text-gray-600">Supplier SKU:</span>
              <span class="font-semibold">{{
                product.product?.supplier_sku || "Not set"
              }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Movement History -->
      <div>
        <h4 class="text-lg font-semibold mb-3 flex items-center">
          <IconCalendar :size="20" class="mr-2" />
          Recent Activity
        </h4>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div class="rounded-lg border bg-gray-50 p-3">
            <p class="text-sm text-gray-600">Last Movement</p>
            <p class="text-sm font-semibold">
              {{ formatDateTime(product.last_movement_at) }}
            </p>
          </div>
          <div class="rounded-lg border bg-gray-50 p-3">
            <p class="text-sm text-gray-600">Last Restock</p>
            <p class="text-sm font-semibold">
              {{ formatDateTime(product.last_restock_at) }}
            </p>
          </div>
          <div class="rounded-lg border bg-gray-50 p-3">
            <p class="text-sm text-gray-600">Last Sale</p>
            <p class="text-sm font-semibold">
              {{ formatDateTime(product.last_sale_at) }}
            </p>
          </div>
        </div>
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
