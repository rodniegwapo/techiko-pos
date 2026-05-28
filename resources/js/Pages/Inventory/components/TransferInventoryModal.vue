<script setup>
import { ref, reactive, computed, watch, toRefs } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { router } from "@inertiajs/vue3";
import { SearchOutlined, SwapOutlined, ShoppingCartOutlined, WarningOutlined } from "@ant-design/icons-vue";
import { notification } from "ant-design-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";

const page = usePage();
const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() =>
  isMdUp.value ? 700 : "calc(100vw - 24px)",
);
const modalRootStyle = computed(() =>
  isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

const { openModal } = useGlobalVariables();

// Global Sanctum inventory API (Ziggy does not expose domain-only `sales.products` on this page).
const inventoryApi = {
  searchProducts: "/api/inventory/search/products",
  products: "/api/inventory/products",
};

const emit = defineEmits(["success", "update:visible"]);

const props = defineProps({
  locations: Array,
  currentLocation: Object,
  visible: Boolean,
  selectedProduct: Object,
  domains: Array,
});

const { visible } = toRefs(props);

// Form state
const form = reactive({
  product_id: null,
  from_location_id: null,
  to_location_id: null,
  quantity: 1,
  notes: "",
  domain: page.props.isGlobalView ? null : (page.props.currentDomain?.name_slug || null),
});

const loading = ref(false);
const productSearch = ref("");
const searchResults = ref([]);
const searchLoading = ref(false);
const selectedProduct = ref(null);
const availableStock = ref(0);
const fromStore = ref(null);
const toStore = ref(null);
const storeLoading = ref(false);

const maxTransferQty = computed(() => Math.max(0, Number(availableStock.value) || 0));

const selectedProductName = computed(
  () => selectedProduct.value?.product?.name ?? selectedProduct.value?.name ?? "",
);

const selectedProductSku = computed(
  () => selectedProduct.value?.product?.SKU ?? selectedProduct.value?.SKU ?? "",
);

const selectedProductUnit = computed(
  () =>
    selectedProduct.value?.product?.unit_of_measure ??
    selectedProduct.value?.unit_of_measure ??
    "pcs",
);

const canSubmitTransfer = computed(
  () =>
    !!selectedProduct.value &&
    !!form.from_location_id &&
    !!form.to_location_id &&
    form.quantity > 0 &&
    maxTransferQty.value > 0 &&
    form.quantity <= maxTransferQty.value,
);

// Domain options
const domainOptions = computed(() => {
  const list = Array.isArray(props.domains)
    ? props.domains
    : [];
  return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

// Initialize form
const initializeForm = () => {
  // If we have a selected product, pre-populate the form
  if (props.selectedProduct) {
    form.product_id = props.selectedProduct.product?.id || null;
    form.from_location_id = props.currentLocation?.id || null;
    form.to_location_id = null;
    form.quantity = 1;
    form.notes = "";

    // Set the product search to show the selected product name
    productSearch.value = props.selectedProduct.product?.name || "";
    searchResults.value = [];
    selectedProduct.value = props.selectedProduct;

    // Set the available stock from the selected inventory (refreshed via API on open)
    availableStock.value = props.selectedProduct.quantity_available || 0;

    if (form.product_id && form.from_location_id) {
      getAvailableStock();
    }
  } else {
    // Default initialization when no product is selected
    form.product_id = null;
    form.from_location_id = props.currentLocation?.id || null;
    form.to_location_id = null;
    form.quantity = 1;
    form.notes = "";
    productSearch.value = "";
    searchResults.value = [];
    selectedProduct.value = null;
    availableStock.value = 0;
  }
};

// Load store summary
const loadStoreItemCount = async (locationId, storeRef) => {
  if (!locationId) {
    storeRef.value = null;
    return;
  }
  
  storeLoading.value = true;
  try {
    const response = await axios.get(`/api/inventory/locations/${locationId}/summary`, {
      params: form.domain ? { domain: form.domain } : {},
    });
    storeRef.value = response.data;
  } catch (error) {
    console.error('Failed to load store summary:', error);
    storeRef.value = null;
  } finally {
    storeLoading.value = false;
  }
};

// Available locations for transfer (exclude from_location)
const availableToLocations = computed(() => {
  return (
    props.locations?.filter((loc) => loc.id !== form.from_location_id) || []
  );
});

// Watch location changes to load store summaries
watch(
  () => form.from_location_id,
  (newLocationId) => {
    loadStoreItemCount(newLocationId, fromStore);

    if (!newLocationId) {
      searchResults.value = [];
      return;
    }

    if (productSearch.value.length >= 2) {
      searchProducts();
    }
  }
);

watch(
  () => form.to_location_id,
  (newLocationId) => {
    loadStoreItemCount(newLocationId, toStore);
  }
);

// Product search
const searchProducts = async () => {
  if (!productSearch.value || productSearch.value.length < 2) {
    searchResults.value = [];
    return;
  }

  if (form.domain && !form.from_location_id) {
    searchResults.value = [];
    return;
  }

  searchLoading.value = true;
  try {
    const params = { search: productSearch.value };
    if (form.domain) {
      params.domain = form.domain;
    }
    if (form.from_location_id) {
      params.location_id = form.from_location_id;
    }
    const response = await axios.get(inventoryApi.searchProducts, { params });
    const collection = response.data.data;
    searchResults.value = Array.isArray(collection)
      ? collection
      : collection?.data ?? [];
  } catch (error) {
    console.error("Product search error:", error);
    searchResults.value = [];
  } finally {
    searchLoading.value = false;
  }
};

// Watch search input
watch(productSearch, () => {
  if (productSearch.value.length >= 2) {
    searchProducts();
  } else {
    searchResults.value = [];
  }
});

// Select product
const selectProduct = async (product) => {
  selectedProduct.value = product;
  form.product_id = product.id;
  productSearch.value = product.name;
  searchResults.value = [];

  availableStock.value = product.location_quantity_available ?? 0;

  await getAvailableStock();
};

// Get available stock
const getAvailableStock = async () => {
  if (!form.product_id || !form.from_location_id) {
    availableStock.value = 0;
    return;
  }

  try {
    const params = {
      product_id: form.product_id,
      location_id: form.from_location_id,
      per_page: 1,
    };

    if (form.domain) {
      params.domain = form.domain;
    }

    const response = await axios.get(inventoryApi.products, { params });
    const inventory = response.data.data?.[0];

    availableStock.value = inventory?.quantity_available ?? 0;
  } catch (error) {
    console.error("Failed to fetch available stock:", error);
    availableStock.value = 0;
  }
};

watch(availableStock, (stock) => {
  const max = Math.max(0, Number(stock) || 0);

  if (max <= 0) {
    form.quantity = 1;
    return;
  }

  if (form.quantity > max) {
    form.quantity = max;
  } else if (form.quantity < 1) {
    form.quantity = 1;
  }
});

// Watch from_location change to update available stock
watch(
  () => form.from_location_id,
  async (newLocationId, oldLocationId) => {
    // Reset to_location if it's the same as from_location
    if (form.to_location_id === form.from_location_id) {
      form.to_location_id = null;
    }

    // Only update stock if we have both location and product, and location actually changed
    if (newLocationId && form.product_id && newLocationId !== oldLocationId) {
      await getAvailableStock();
    }
  }
);

// Watch for product changes to update available stock
watch(
  () => form.product_id,
  async (newProductId, oldProductId) => {
    // Only update stock if we have both product and location, and product actually changed
    if (
      newProductId &&
      form.from_location_id &&
      newProductId !== oldProductId
    ) {
      await getAvailableStock();
    }
  }
);

// Submit form
const handleSubmit = async () => {
  if (!form.product_id) {
    notification.warning({
      message: "No Product Selected",
      description: "Please select a product to transfer",
    });
    return;
  }

  if (!form.from_location_id || !form.to_location_id) {
    notification.warning({
      message: "Missing Locations",
      description: "Please select both from and to locations",
    });
    return;
  }

  if (form.quantity <= 0) {
    notification.warning({
      message: "Invalid Quantity",
      description: "Please enter a valid quantity",
    });
    return;
  }

  if (form.quantity > maxTransferQty.value) {
    notification.warning({
      message: "Insufficient Stock",
      description: `Only ${maxTransferQty.value} units available`,
    });
    return;
  }

  loading.value = true;

  try {
    const response = await axios.post(route("inventory.transfer"), form);

    notification.success({
      message: "Transfer Successful",
      description: "Inventory transferred successfully",
    });
    closeModal();
    emit("success");
  } catch (error) {
    console.error("Submit error:", error);
    const data = error.response?.data;
    const errorMessage =
      data?.errors?.quantity?.[0] ||
      data?.message ||
      "An unexpected error occurred";
    notification.error({
      message: "Transfer Failed",
      description: errorMessage,
    });
  } finally {
    loading.value = false;
  }
};

// Clear selected product
const clearSelectedProduct = () => {
  form.product_id = null;
  selectedProduct.value = null;
  availableStock.value = 0;
  productSearch.value = "";
};

// Close modal
const closeModal = () => {
  emit("update:visible", false);
  initializeForm();
};

// Watch for selectedProduct changes
watch(
  () => props.selectedProduct,
  (newProduct) => {
    if (newProduct && props.visible) {
      initializeForm();
    }
  },
  { immediate: true }
);

// Initialize when modal opens
watch(
  () => props.visible,
  (isOpen) => {
    if (isOpen) {
      initializeForm();

      if (form.from_location_id) {
        loadStoreItemCount(form.from_location_id, fromStore);
      }
    }
  }
);
</script>

<template>
  <a-modal
    v-model:visible="visible"
    :width="modalWidth"
    :style="modalRootStyle"
    wrap-class-name="modal-footer-full-mobile"
    centered
    :confirm-loading="loading"
    @ok="(e) => { e.preventDefault(); handleSubmit(); }"
    @cancel="closeModal"
  >
    <template #title>
      <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <span>Transfer Inventory</span>
        <div class="flex flex-wrap items-center gap-2">
          <div v-if="fromStore" class="flex items-center space-x-1">
            <span class="text-xs text-gray-500">From:</span>
            <a-tag color="blue" size="small">
              <ShoppingCartOutlined :size="12" class="mr-1" />
              {{ fromStore.total_products_count }}
            </a-tag>
          </div>
          <SwapOutlined class="text-gray-400" />
          <div v-if="toStore" class="flex items-center space-x-1">
            <span class="text-xs text-gray-500">To:</span>
            <a-tag color="green" size="small">
              <ShoppingCartOutlined :size="12" class="mr-1" />
              {{ toStore.total_products_count }}
            </a-tag>
          </div>
        </div>
      </div>
    </template>
    <div class="space-y-6">
      <!-- Product Selection -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Select Product *
        </label>

        <!-- Show selected product if pre-selected -->
        <div
          v-if="selectedProduct && form.product_id"
          class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200"
        >
          <div class="flex items-center justify-between">
            <div>
              <p class="font-semibold text-blue-900">
                {{ selectedProductName }}
              </p>
              <p class="text-sm text-blue-700">
                SKU: {{ selectedProductSku }}
              </p>
              <p class="text-sm text-blue-700">
                Available: {{ availableStock }}
                {{ selectedProductUnit }}
              </p>
            </div>
            <a-button type="link" size="small" @click="clearSelectedProduct">
              Change Product
            </a-button>
          </div>
        </div>

        <div class="relative" v-if="!form.product_id">
          <a-input
            v-model:value="productSearch"
            placeholder="Search products by name, SKU, or barcode..."
            :loading="searchLoading"
            :disabled="loading"
          >
            <template #prefix>
              <SearchOutlined />
            </template>
          </a-input>

          <!-- Search Results Dropdown -->
          <div
            v-if="searchResults.length > 0"
            class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-auto"
          >
            <div
              v-for="product in searchResults"
              :key="product.id"
              class="px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0"
              @click="selectProduct(product)"
            >
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-medium text-gray-900">{{ product.name }}</p>
                  <p class="text-sm text-gray-500">SKU: {{ product.SKU }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm font-medium">
                    ₱{{ product.price?.toFixed(2) || "0.00" }}
                  </p>
                  <p class="text-xs text-gray-500">
                    {{ product.category?.name || "No Category" }}
                  </p>
                  <p
                    v-if="product.location_quantity_available != null"
                    class="text-xs font-medium"
                    :class="
                      product.location_quantity_available > 0
                        ? 'text-green-600'
                        : 'text-red-600'
                    "
                  >
                    Available: {{ product.location_quantity_available }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Location Selection -->
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            From Location *
          </label>
          <a-select
            v-model:value="form.from_location_id"
            placeholder="Select source location"
            class="w-full"
            :disabled="loading"
            :loading="storeLoading"
          >
            <a-select-option
              v-for="location in locations"
              :key="location.id"
              :value="location.id"
            >
              <div class="flex justify-between items-center">
                <span>{{ location.name }}</span>
                <span class="text-gray-500 text-xs">
                  {{ location.address }}
                </span>
              </div>
            </a-select-option>
          </a-select>
          
          <!-- From Store Summary -->
          <div v-if="fromStore" class="mt-2 p-2 bg-blue-50 rounded border border-blue-200">
            <div class="grid grid-cols-3 gap-2 text-center">
              <div>
                <p class="text-xs font-bold text-blue-600">{{ fromStore.total_products_count || 0 }}</p>
                <p class="text-xs text-gray-600">Total</p>
              </div>
              <div>
                <p class="text-xs font-bold text-green-600">{{ fromStore.in_stock_products_count || 0 }}</p>
                <p class="text-xs text-gray-600">In Stock</p>
              </div>
              <div>
                <p class="text-xs font-bold text-red-600">{{ fromStore.out_of_stock_products_count || 0 }}</p>
                <p class="text-xs text-gray-600">Out</p>
              </div>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            To Location *
          </label>
          <a-select
            v-model:value="form.to_location_id"
            placeholder="Select destination location"
            class="w-full"
            :disabled="loading"
            :loading="storeLoading"
          >
            <a-select-option
              v-for="location in availableToLocations"
              :key="location.id"
              :value="location.id"
            >
              <div class="flex justify-between items-center">
                <span>{{ location.name }}</span>
                <span class="text-gray-500 text-xs">
                  {{ location.address }}
                </span>
              </div>
            </a-select-option>
          </a-select>
          
          <!-- To Store Summary -->
          <div v-if="toStore" class="mt-2 p-2 bg-green-50 rounded border border-green-200">
            <div class="grid grid-cols-3 gap-2 text-center">
              <div>
                <p class="text-xs font-bold text-green-600">{{ toStore.total_products_count || 0 }}</p>
                <p class="text-xs text-gray-600">Total</p>
              </div>
              <div>
                <p class="text-xs font-bold text-blue-600">{{ toStore.in_stock_products_count || 0 }}</p>
                <p class="text-xs text-gray-600">In Stock</p>
              </div>
              <div>
                <p class="text-xs font-bold text-yellow-600">{{ toStore.low_stock_products_count || 0 }}</p>
                <p class="text-xs text-gray-600">Low</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Domain field for global view -->
      <div v-if="page.props.isGlobalView">
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Domain *
        </label>
        <a-select
          v-model:value="form.domain"
          placeholder="Select domain"
          class="w-full"
          :disabled="loading"
        >
          <a-select-option
            v-for="domain in domainOptions"
            :key="domain.value"
            :value="domain.value"
          >
            {{ domain.label }}
          </a-select-option>
        </a-select>
      </div>

      <!-- Transfer Arrow -->
      <div
        v-if="form.from_location_id && form.to_location_id"
        class="text-center"
      >
        <div
          class="inline-flex items-center space-x-4 p-4 bg-gray-50 rounded-lg"
        >
          <div class="text-center">
            <p class="font-medium text-gray-900">
              {{ locations?.find((l) => l.id === form.from_location_id)?.name }}
            </p>
            <p class="text-sm text-gray-500">From</p>
          </div>

          <SwapOutlined class="text-2xl text-blue-600" />

          <div class="text-center">
            <p class="font-medium text-gray-900">
              {{ locations?.find((l) => l.id === form.to_location_id)?.name }}
            </p>
            <p class="text-sm text-gray-500">To</p>
          </div>
        </div>
      </div>

      <!-- Quantity -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Quantity to Transfer *
        </label>
        <a-input-number
          v-model:value="form.quantity"
          :min="maxTransferQty > 0 ? 1 : 0"
          :max="maxTransferQty > 0 ? maxTransferQty : undefined"
          :step="1"
          :precision="0"
          class="w-full"
          :disabled="loading || !selectedProduct || maxTransferQty <= 0"
        />
        <p v-if="maxTransferQty > 0" class="text-sm text-gray-500 mt-1">
          Maximum available: {{ maxTransferQty }}
          {{ selectedProductUnit }}
        </p>
        <p
          v-if="form.quantity > maxTransferQty && maxTransferQty > 0"
          class="text-sm text-red-600 mt-1"
        >
          Quantity exceeds available stock (max {{ maxTransferQty }}).
        </p>
      </div>

      <!-- Notes -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Notes (Optional)
        </label>
        <a-textarea
          v-model:value="form.notes"
          placeholder="Add any notes about this transfer..."
          :rows="3"
          :disabled="loading"
        />
      </div>

      <!-- Stock Warning -->
      <div
        v-if="selectedProduct && maxTransferQty <= 0"
        class="p-4 bg-red-50 border border-red-200 rounded-lg"
      >
        <div class="flex items-center">
          <div class="text-red-600 mr-3">⚠️</div>
          <div>
            <p class="font-medium text-red-800">No Stock Available</p>
            <p class="text-sm text-red-700">
              This product has no available stock at the selected location.
            </p>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="modal-footer-actions flex w-full flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <span v-if="selectedProduct" class="text-sm text-gray-500">
            {{ selectedProductName }} • {{ form.quantity }}
            {{ selectedProductUnit }}
          </span>
        </div>
        <div class="flex w-full flex-col gap-2 md:w-auto md:flex-row">
          <a-button class="w-full md:w-auto" @click="closeModal" :disabled="loading">
            Cancel
          </a-button>
          <a-button
            type="primary"
            class="w-full md:w-auto"
            @click="handleSubmit"
            :loading="loading"
            :disabled="!canSubmitTransfer"
          >
            Transfer Inventory
          </a-button>
        </div>
      </div>
    </template>
  </a-modal>
</template>

<style scoped>
.ant-input-number {
  width: 100%;
}
</style>
