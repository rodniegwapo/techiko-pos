<script setup>
import { ref, reactive, computed, watch, toRefs } from "vue";
import { useMediaQuery } from "@vueuse/core";
import {
  SearchOutlined,
  SwapOutlined,
  ShoppingCartOutlined,
  DeleteOutlined,
} from "@ant-design/icons-vue";
import { notification } from "ant-design-vue";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";

const page = usePage();
const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() =>
  isMdUp.value ? 760 : "calc(100vw - 24px)",
);
const modalRootStyle = computed(() =>
  isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);

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
  // An inventory row (with .product) when opened from a product's "Transfer" action.
  selectedProduct: Object,
  domains: Array,
});

const { visible } = toRefs(props);

const form = reactive({
  from_location_id: null,
  to_location_id: null,
  notes: "",
  domain: page.props.isGlobalView ? null : (page.props.currentDomain?.name_slug || null),
});

/** The products being moved: { product_id, name, sku, unit, available, quantity, error } */
const items = ref([]);

const loading = ref(false);
const productSearch = ref("");
const searchResults = ref([]);
const searchLoading = ref(false);
const fromStore = ref(null);
const toStore = ref(null);
const storeLoading = ref(false);

const totalUnits = computed(() =>
  items.value.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0),
);

const rowIsValid = (item) =>
  item.available > 0 && item.quantity >= 1 && item.quantity <= item.available;

const canSubmitTransfer = computed(
  () =>
    !!form.from_location_id &&
    !!form.to_location_id &&
    items.value.length > 0 &&
    items.value.every(rowIsValid),
);

const domainOptions = computed(() => {
  const list = Array.isArray(props.domains) ? props.domains : [];
  return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

const locationName = (id) => props.locations?.find((l) => l.id === id)?.name;

const availableToLocations = computed(
  () => props.locations?.filter((loc) => loc.id !== form.from_location_id) || [],
);

const rowFor = (product, available) => ({
  product_id: product.id,
  name: product.name,
  sku: product.SKU,
  unit: product.unit_of_measure || "pcs",
  available: Math.max(0, Number(available) || 0),
  quantity: 1,
  error: null,
});

const initializeForm = () => {
  form.from_location_id = props.currentLocation?.id || null;
  form.to_location_id = null;
  form.notes = "";
  productSearch.value = "";
  searchResults.value = [];
  items.value = [];

  // Opened from one product's row: start with that product.
  const row = props.selectedProduct;
  if (row?.product) {
    items.value = [rowFor(row.product, row.quantity_available)];
    refreshStock(items.value[0]);
  }
};

// Store summary (counts shown under each store picker)
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
    console.error("Failed to load store summary:", error);
    storeRef.value = null;
  } finally {
    storeLoading.value = false;
  }
};

/** What the source store has of this product right now. */
const refreshStock = async (item) => {
  if (!item || !form.from_location_id) return;
  try {
    const params = { product_id: item.product_id, location_id: form.from_location_id, per_page: 1 };
    if (form.domain) params.domain = form.domain;
    const response = await axios.get(inventoryApi.products, { params });
    item.available = Math.max(0, Number(response.data.data?.[0]?.quantity_available) || 0);
    if (item.available > 0 && item.quantity > item.available) {
      item.quantity = item.available;
    }
  } catch (error) {
    console.error("Failed to fetch available stock:", error);
  }
};

watch(
  () => form.from_location_id,
  (newLocationId, oldLocationId) => {
    loadStoreItemCount(newLocationId, fromStore);

    if (form.to_location_id === newLocationId) {
      form.to_location_id = null;
    }

    // The rows' stock was for the old store, so start the list over.
    if (oldLocationId && newLocationId !== oldLocationId && items.value.length) {
      items.value = [];
      notification.info({
        message: "Products cleared",
        description: "The list was cleared because the source store changed.",
      });
    }

    if (!newLocationId) {
      searchResults.value = [];
    } else if (productSearch.value.length >= 2) {
      searchProducts();
    }
  },
);

watch(
  () => form.to_location_id,
  (newLocationId) => loadStoreItemCount(newLocationId, toStore),
);

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
    if (form.domain) params.domain = form.domain;
    if (form.from_location_id) params.location_id = form.from_location_id;
    const response = await axios.get(inventoryApi.searchProducts, { params });
    const collection = response.data.data;
    searchResults.value = Array.isArray(collection) ? collection : collection?.data ?? [];
  } catch (error) {
    console.error("Product search error:", error);
    searchResults.value = [];
  } finally {
    searchLoading.value = false;
  }
};

watch(productSearch, () => {
  if (productSearch.value.length >= 2) {
    searchProducts();
  } else {
    searchResults.value = [];
  }
});

const addProduct = (product) => {
  if (items.value.some((item) => item.product_id === product.id)) {
    notification.warning({
      message: "Already added",
      description: `${product.name} is already in the list; change its quantity instead.`,
    });
    return;
  }

  items.value.push(rowFor(product, product.location_quantity_available));
  productSearch.value = "";
  searchResults.value = [];
};

const removeItem = (index) => {
  items.value.splice(index, 1);
};

const handleSubmit = async () => {
  if (!form.from_location_id || !form.to_location_id) {
    notification.warning({
      message: "Missing Locations",
      description: "Please select both from and to locations",
    });
    return;
  }
  if (!items.value.length) {
    notification.warning({
      message: "No Products",
      description: "Add at least one product to transfer",
    });
    return;
  }
  if (!items.value.every(rowIsValid)) {
    notification.warning({
      message: "Check the quantities",
      description: "Each product needs a quantity between 1 and what the source store has.",
    });
    return;
  }

  loading.value = true;
  items.value.forEach((item) => (item.error = null));

  try {
    await axios.post(route("inventory.transfer"), {
      from_location_id: form.from_location_id,
      to_location_id: form.to_location_id,
      notes: form.notes || null,
      domain: form.domain,
      items: items.value.map((item) => ({
        product_id: item.product_id,
        quantity: item.quantity,
      })),
    });

    notification.success({
      message: "Transfer Successful",
      description:
        items.value.length === 1
          ? "Inventory transferred successfully"
          : `${items.value.length} products transferred successfully`,
    });
    closeModal();
    emit("success");
  } catch (error) {
    console.error("Submit error:", error);
    const data = error.response?.data;

    // Show each row's problem under that row.
    Object.entries(data?.errors ?? {}).forEach(([key, messages]) => {
      const match = key.match(/^items\.(\d+)\./);
      if (match && items.value[Number(match[1])]) {
        items.value[Number(match[1])].error = messages?.[0];
      }
    });

    notification.error({
      message: "Transfer Failed",
      description: data?.message || "An unexpected error occurred",
    });
  } finally {
    loading.value = false;
  }
};

const closeModal = () => {
  emit("update:visible", false);
  initializeForm();
};

watch(
  () => props.selectedProduct,
  (newProduct) => {
    if (newProduct && props.visible) {
      initializeForm();
    }
  },
  { immediate: true },
);

watch(
  () => props.visible,
  (isOpen) => {
    if (isOpen) {
      initializeForm();
      if (form.from_location_id) {
        loadStoreItemCount(form.from_location_id, fromStore);
      }
    }
  },
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

      <!-- Add products -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Products *
        </label>
        <div class="relative">
          <a-input
            v-model:value="productSearch"
            placeholder="Search products to add by name, SKU, or barcode..."
            :loading="searchLoading"
            :disabled="loading || !form.from_location_id"
          >
            <template #prefix>
              <SearchOutlined />
            </template>
          </a-input>

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

        <!-- Products to move -->
        <div v-if="items.length" class="mt-3 overflow-hidden rounded-lg border border-gray-200">
          <div
            class="hidden grid-cols-12 gap-2 bg-gray-50 px-3 py-2 text-xs font-medium text-gray-500 md:grid"
          >
            <div class="col-span-6">Product</div>
            <div class="col-span-2 text-right">Available</div>
            <div class="col-span-3">Quantity</div>
            <div class="col-span-1"></div>
          </div>
          <div
            v-for="(item, index) in items"
            :key="item.product_id"
            class="grid grid-cols-12 items-center gap-2 border-t border-gray-100 px-3 py-2 first:border-t-0"
            :data-testid="`transfer-row-${index}`"
          >
            <div class="col-span-12 min-w-0 md:col-span-6">
              <p class="truncate font-medium text-gray-900">{{ item.name }}</p>
              <p class="text-xs text-gray-500">SKU: {{ item.sku || "—" }}</p>
            </div>
            <div
              class="col-span-4 text-sm md:col-span-2 md:text-right"
              :class="item.available > 0 ? 'text-gray-700' : 'text-red-600'"
            >
              <span class="md:hidden text-gray-500">Available: </span>
              {{ item.available }} {{ item.unit }}
            </div>
            <div class="col-span-6 md:col-span-3">
              <a-input-number
                v-model:value="item.quantity"
                :min="1"
                :max="item.available > 0 ? item.available : undefined"
                :precision="0"
                :disabled="loading || item.available <= 0"
                :aria-label="`Quantity of ${item.name}`"
                class="w-full"
              />
            </div>
            <div class="col-span-2 text-right md:col-span-1">
              <a-button
                type="text"
                danger
                size="small"
                :aria-label="`Remove ${item.name}`"
                @click="removeItem(index)"
              >
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </div>
            <p
              v-if="item.available <= 0"
              class="col-span-12 mb-0 text-xs text-red-600"
            >
              None of this product at the source store.
            </p>
            <p
              v-else-if="item.quantity > item.available"
              class="col-span-12 mb-0 text-xs text-red-600"
            >
              Only {{ item.available }} available.
            </p>
            <p v-if="item.error" class="col-span-12 mb-0 text-xs text-red-600">
              {{ item.error }}
            </p>
          </div>
        </div>
        <p v-else class="mt-2 text-sm text-gray-500">
          Search above to add the products to move.
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
    </div>

    <template #footer>
      <div class="modal-footer-actions flex w-full flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="text-sm text-gray-500">
          <span v-if="items.length">
            {{ items.length }} {{ items.length === 1 ? "product" : "products" }} •
            {{ totalUnits }} units
            <template v-if="form.from_location_id && form.to_location_id">
              • {{ locationName(form.from_location_id) }} →
              {{ locationName(form.to_location_id) }}
            </template>
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
