<script setup>
import { ref, reactive, computed, watch,toRefs } from "vue";
import { router } from "@inertiajs/vue3";
import { IconPlus, IconTrash, IconSearch, IconShoppingCart, IconAlertTriangle, IconX } from "@tabler/icons-vue";
import { notification } from "ant-design-vue";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";

const page = usePage();

const emit = defineEmits(["success", "update:visible"]);

const props = defineProps({
  locations: Array,
  currentLocation: Object,
  visible: Boolean,
  domains: Array,
});

const { visible } = toRefs(props);
// Form state
const form = reactive({
  location_id: null,
  items: [],
  reference_type: null,
  reference_id: null,
  domain: page.props.isGlobalView ? null : (page.props.currentDomain?.name_slug || null),
});

const loading = ref(false);
const productSearch = ref("");
const searchResults = ref([]);
const searchLoading = ref(false);
const selectedStore = ref(null);
const storeLoading = ref(false);

// Domain options
const domainOptions = computed(() => {
  const list = Array.isArray(props.domains)
    ? props.domains
    : [];
  return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

// Load store summary when location changes
const loadStoreItemCount = async (locationId) => {
  if (!locationId) {
    selectedStore.value = null;
    return;
  }
  
  storeLoading.value = true;
  try {
    const response = await axios.get(`/api/inventory/locations/${locationId}/summary`);
    selectedStore.value = response.data;
  } catch (error) {
    console.error('Failed to load store summary:', error);
    selectedStore.value = null;
  } finally {
    storeLoading.value = false;
  }
};

// Initialize form
const initializeForm = () => {
  form.location_id = props.currentLocation?.id || null;
  form.items = [];
  form.reference_type = null;
  form.reference_id = null;
  productSearch.value = "";
  searchResults.value = [];
  loadStoreItemCount(form.location_id);
};

// Watch location change to load store summary
watch(
  () => form.location_id,
  (newLocationId) => {
    loadStoreItemCount(newLocationId);
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
    const response = await axios.get(route('sales.products'), {
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

// Add product to items
const addProduct = (product) => {
  const existingIndex = form.items.findIndex(
    (item) => item.product_id === product.id
  );

  if (existingIndex >= 0) {
    // If product already exists, focus on quantity input
    notification.warning({
      message: "Product Already Added",
      description: "This product is already in the list",
    });
    return;
  }

  form.items.push({
    product_id: product.id,
    product: product,
    quantity: 1,
    unit_cost: product.cost || 0,
    batch_number: "",
    expiry_date: null,
    notes: "",
  });

  productSearch.value = "";
  searchResults.value = [];
};

// Remove item
const removeItem = (index) => {
  form.items.splice(index, 1);
};

// Calculate total value
const totalValue = computed(() => {
  return form.items.reduce((sum, item) => {
    return sum + item.quantity * item.unit_cost;
  }, 0);
});

// Submit form
const handleSubmit = async () => {
  if (form.items.length === 0) {
    notification.warning({
      message: "No Items",
      description: "Please add at least one product to receive",
    });
    return;
  }

  loading.value = true;

  try {
    const response = await axios.post(route('inventory.receive'), form);

    if (response.data.success) {
      notification.success({
        message: "Receive Successful",
        description: "Inventory received successfully",
      });
      closeModal();
      emit("success");
    } else {
      notification.error({
        message: "Receive Failed",
        description: response.data.message || "Failed to receive inventory",
      });
    }
  } catch (error) {
    console.error("Submit error:", error);
    const errorMessage =
      error.response?.data?.message || "An unexpected error occurred";
    notification.error({
      message: "Receive Failed",
      description: errorMessage,
    });
  } finally {
    loading.value = false;
  }
};

// Close modal
const closeModal = () => {
  emit("update:visible", false);
  initializeForm();
};

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
    width="900px"
    :confirm-loading="loading"
    @ok="handleSubmit"
    @cancel="closeModal"
  >
    <template #title>
      <div class="flex items-center justify-between">
        <span>Receive Inventory</span>
        <div v-if="selectedStore" class="flex items-center space-x-2">
          <a-tag color="blue" size="small">
            <IconShoppingCart :size="14" class="mr-1" />
            {{ selectedStore.total_products_count }} items
          </a-tag>
          <a-tag v-if="selectedStore.low_stock_products_count > 0" color="orange" size="small">
            <IconAlertTriangle :size="14" class="mr-1" />
            {{ selectedStore.low_stock_products_count }} low
          </a-tag>
        </div>
      </div>
    </template>

    <div class="space-y-6">
      <!-- Location Selection -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Receiving Location *
        </label>
        <a-select
          v-model:value="form.location_id"
          placeholder="Select location"
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
              <span class="text-gray-500 text-sm">
                {{ location.address }}
              </span>
            </div>
          </a-select-option>
        </a-select>
        
        <!-- Store Summary -->
        <div v-if="selectedStore" class="mt-3 p-3 bg-blue-50 rounded-lg border border-blue-200">
          <div class="grid grid-cols-4 gap-3 text-center">
            <div>
              <p class="text-sm font-bold text-blue-600">{{ selectedStore.total_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">Total</p>
            </div>
            <div>
              <p class="text-sm font-bold text-green-600">{{ selectedStore.in_stock_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">In Stock</p>
            </div>
            <div>
              <p class="text-sm font-bold text-yellow-600">{{ selectedStore.low_stock_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">Low Stock</p>
            </div>
            <div>
              <p class="text-sm font-bold text-red-600">{{ selectedStore.out_of_stock_products_count || 0 }}</p>
              <p class="text-xs text-gray-600">Out of Stock</p>
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

      <!-- Reference Information -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Reference Type
          </label>
          <a-input
            v-model:value="form.reference_type"
            placeholder="e.g., Purchase Order, Manual Entry"
            :disabled="loading"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Reference ID
          </label>
          <a-input-number
            v-model:value="form.reference_id"
            placeholder="Reference number"
            class="w-full"
            :disabled="loading"
          />
        </div>
      </div>

      <!-- Product Search -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Add Products
        </label>
        <div class="relative">
          <a-input
            v-model:value="productSearch"
            placeholder="Search products by name, SKU, or barcode..."
            :loading="searchLoading"
            :disabled="loading"
          >
            <template #prefix>
              <IconSearch :size="16" />
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
              @click="addProduct(product)"
            >
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-medium text-gray-900">{{ product.name }}</p>
                  <p class="text-sm text-gray-500">SKU: {{ product.SKU }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm font-medium">
                    ₱{{ product.cost?.toFixed(2) || "0.00" }}
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

      <!-- Items List -->
      <div v-if="form.items.length > 0">
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Items to Receive ({{ form.items.length }})
        </label>

        <div class="border border-gray-200 rounded-lg overflow-hidden">
          <div
            class="bg-gray-50 px-4 py-2 grid grid-cols-12 gap-2 text-sm font-medium text-gray-700"
          >
            <div class="col-span-3">Product</div>
            <div class="col-span-2">Quantity</div>
            <div class="col-span-2">Unit Cost</div>
            <div class="col-span-2">Batch #</div>
            <div class="col-span-2">Expiry Date</div>
            <div class="col-span-1">Action</div>
          </div>

          <div
            v-for="(item, index) in form.items"
            :key="index"
            class="px-4 py-3 grid grid-cols-12 gap-2 items-center border-b border-gray-100 last:border-b-0"
          >
            <!-- Product Info -->
            <div class="col-span-3">
              <p class="font-medium text-sm">{{ item.product.name }}</p>
              <p class="text-xs text-gray-500">{{ item.product.SKU }}</p>
            </div>

            <!-- Quantity -->
            <div class="col-span-2">
              <a-input-number
                v-model:value="item.quantity"
                :min="1"
                :step="1"
                :precision="0"
                class="w-full"
                :disabled="loading"
              />
            </div>

            <!-- Unit Cost -->
            <div class="col-span-2">
              <a-input-number
                v-model:value="item.unit_cost"
                :min="0"
                :step="0.01"
                :precision="2"
                class="w-full"
                :disabled="loading"
              />
            </div>

            <!-- Batch Number -->
            <div class="col-span-2">
              <a-input
                v-model:value="item.batch_number"
                placeholder="Optional"
                :disabled="loading"
              />
            </div>

            <!-- Expiry Date -->
            <div class="col-span-2">
              <a-date-picker
                v-model:value="item.expiry_date"
                placeholder="Optional"
                class="w-full"
                :disabled="loading"
              />
            </div>

            <!-- Remove Button -->
            <div class="col-span-1 text-center">
              <a-button
                type="text"
                danger
                size="small"
                @click="removeItem(index)"
                :disabled="loading"
              >
                <IconTrash :size="16" />
              </a-button>
            </div>
          </div>
        </div>

        <!-- Total Value -->
        <div class="mt-4 text-right">
          <p class="text-lg font-semibold">
            Total Value: ₱{{ totalValue.toFixed(2) }}
          </p>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="text-center py-8 text-gray-500">
        <IconPlus :size="48" class="mx-auto mb-2" />
        <p>No products added yet</p>
        <p class="text-sm">Search and add products to receive inventory</p>
      </div>
    </div>

    <template #footer>
      <div class="flex justify-between">
        <div>
          <span class="text-sm text-gray-500">
            {{ form.items.length }} item(s) • Total: ₱{{
              totalValue.toFixed(2)
            }}
          </span>
        </div>
        <div class="space-x-2">
          <a-button @click="closeModal" :disabled="loading"> Cancel </a-button>
          <a-button
            type="primary"
            @click="handleSubmit"
            :loading="loading"
            :disabled="form.items.length === 0"
          >
            Receive Inventory
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
