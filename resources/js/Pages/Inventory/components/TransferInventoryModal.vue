<script setup>
import { ref, reactive, computed, watch, toRefs } from "vue";
import { router } from "@inertiajs/vue3";
import { SearchOutlined, SwapOutlined, ShoppingCartOutlined, WarningOutlined } from "@ant-design/icons-vue";
import { notification } from "ant-design-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import axios from "axios";

const { openModal } = useGlobalVariables();

const emit = defineEmits(["success", "update:visible"]);

const props = defineProps({
  locations: Array,
  currentLocation: Object,
  visible: Boolean,
  selectedProduct: Object,
});

const { visible } = toRefs(props);

// Form state
const form = reactive({
  product_id: null,
  from_location_id: null,
  to_location_id: null,
  quantity: 1,
  notes: "",
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

    // Set the available stock from the selected inventory
    availableStock.value = props.selectedProduct.quantity_available || 0;
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
    const response = await axios.get(`/api/inventory/locations/${locationId}/summary`);
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

  searchLoading.value = true;
  try {
    const response = await axios.get(route("sales.products"), {
      params: { search: productSearch.value },
    });
    searchResults.value = response.data.data || [];
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

  // Get available stock for this product at from_location
  await getAvailableStock();
};

// Get available stock
const getAvailableStock = async () => {
  if (!form.product_id || !form.from_location_id) {
    availableStock.value = 0;
    return;
  }

  try {
    // Get the product info to use for search
    let searchTerm = "";
    if (selectedProduct.value) {
      // If we have selectedProduct, get the search term from the right place
      if (selectedProduct.value.product) {
        // This is from inventory table (pre-selected product)
        searchTerm =
          selectedProduct.value.product.SKU ||
          selectedProduct.value.product.name;
      } else if (selectedProduct.value.SKU || selectedProduct.value.name) {
        // This is from product search
        searchTerm = selectedProduct.value.SKU || selectedProduct.value.name;
      }
    }

    // Check if we're making the right API call
    const apiUrl = route("inventory.products");

    // First try with search term
    let response = await axios.get(apiUrl, {
      params: {
        location_id: form.from_location_id,
        search: searchTerm,
        per_page: 100,
      },
    });

    // Check if response is HTML (error case)
    if (
      typeof response.data === "string" &&
      response.data.includes("<!DOCTYPE html>")
    ) {
      availableStock.value = 0;
      return;
    }

    let inventory = response.data.data?.find(
      (inv) => inv.product_id === form.product_id
    );

    // If not found with search term, try without search (get all products for this location)
    if (!inventory && searchTerm) {
      response = await axios.get(apiUrl, {
        params: {
          location_id: form.from_location_id,
          per_page: 1000, // Get more results
        },
      });

      // Check if response is HTML (error case)
      if (
        typeof response.data === "string" &&
        response.data.includes("<!DOCTYPE html>")
      ) {
        availableStock.value = 0;
        return;
      }

      inventory = response.data.data?.find(
        (inv) => inv.product_id === form.product_id
      );
    }

    availableStock.value = inventory?.quantity_available || 0;
  } catch (error) {
    availableStock.value = 0;
  }
};

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

  if (form.quantity > availableStock.value) {
    notification.warning({
      message: "Insufficient Stock",
      description: `Only ${availableStock.value} units available`,
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
    const errorMessage =
      error.response?.data?.message || "An unexpected error occurred";
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
    }
  }
);
</script>

<template>
  <a-modal
    v-model:visible="visible"
    width="700px"
    :confirm-loading="loading"
    @ok="handleSubmit"
    @cancel="closeModal"
  >
    <template #title>
      <div class="flex items-center justify-between">
        <span>Transfer Inventory</span>
        <div class="flex items-center space-x-2">
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
                {{ selectedProduct.product?.name }}
              </p>
              <p class="text-sm text-blue-700">
                SKU: {{ selectedProduct.product?.SKU }}
              </p>
              <p class="text-sm text-blue-700">
                Available: {{ availableStock }}
                {{ selectedProduct.product?.unit_of_measure || "pcs" }}
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
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Location Selection -->
      <div class="grid grid-cols-2 gap-4">
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
              v-for="location in locations"
              :key="location.id"
              :value="location.id"
            >
              {{ location.name }}
            </a-select-option>
          </a-select>
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
          :min="1"
          :max="availableStock"
          :step="1"
          :precision="0"
          class="w-full"
          :disabled="loading || !selectedProduct"
        />
        <p v-if="availableStock > 0" class="text-sm text-gray-500 mt-1">
          Maximum available: {{ availableStock }}
          {{ selectedProduct?.unit_of_measure || "pcs" }}
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
        v-if="selectedProduct && availableStock <= 0"
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
      <div class="flex justify-between">
        <div>
          <span v-if="selectedProduct" class="text-sm text-gray-500">
            {{ selectedProduct.product?.name }} • {{ form.quantity }}
            {{ selectedProduct.product?.unit_of_measure || "pcs" }}
          </span>
        </div>
        <div class="space-x-2">
          <a-button @click="closeModal" :disabled="loading"> Cancel </a-button>
          <a-button
            type="primary"
            @click="handleSubmit"
            :loading="loading"
            :disabled="
              !selectedProduct ||
              !form.from_location_id ||
              !form.to_location_id ||
              form.quantity <= 0 ||
              form.quantity > availableStock
            "
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
