<script setup>
import { ref, reactive, computed, watch, onMounted } from "vue";
import { router, Head } from "@inertiajs/vue3";
import {
  PlusOutlined,
  MinusOutlined,
  SearchOutlined,
  ArrowLeftOutlined,
  SaveOutlined,
} from "@ant-design/icons-vue";
import { notification } from "ant-design-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import axios from "axios";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";

const { spinning } = useGlobalVariables();

const props = defineProps({
  adjustment: Object,
  locations: Array,
  reasons: Object,
});

// Form state
const form = reactive({
  location_id: null,
  type: "recount",
  reason: null,
  description: "",
  items: [],
});

const loading = ref(false);
const productSearch = ref("");
const searchResults = ref([]);
const searchLoading = ref(false);

// Initialize form with existing adjustment data
onMounted(() => {
  if (props.adjustment) {
    form.location_id = props.adjustment.location_id;
    form.type = props.adjustment.type || "recount";
    form.reason = props.adjustment.reason;
    form.description = props.adjustment.description || "";
    
    // Initialize items from existing adjustment
    form.items = props.adjustment.items?.map(item => ({
      id: item.id, // Keep the existing item ID for updates
      product_id: item.product_id,
      product: item.product,
      system_quantity: item.system_quantity || 0,
      actual_quantity: item.actual_quantity || 0,
      adjustment_quantity: item.adjustment_quantity || 0,
      unit_cost: item.unit_cost || 0,
      total_cost_change: item.total_cost_change || 0,
      batch_number: item.batch_number || "",
      expiry_date: item.expiry_date || null,
      notes: item.notes || "",
    })) || [];
  }
});

// Product search
const searchProducts = async () => {
  if (!productSearch.value || productSearch.value.length < 2) {
    searchResults.value = [];
    return;
  }

  if (!form.location_id) {
    notification.warning({
      message: "Location Required",
      description: "Please select a location first to search products",
    });
    return;
  }

  searchLoading.value = true;
  try {
    const response = await axios.get(route("inventory.adjustment-products"), {
      params: {
        search: productSearch.value,
        location_id: form.location_id,
      },
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

// Add product to adjustment
const addProduct = (product) => {
  const existingIndex = form.items.findIndex(
    (item) => item.product_id === product.id
  );

  if (existingIndex >= 0) {
    notification.warning({
      message: "Product Already Added",
      description: "This product is already in the adjustment list",
    });
    return;
  }

  const newItem = {
    product_id: product.id,
    product: product,
    system_quantity: product.current_stock || 0,
    actual_quantity: product.current_stock || 0,
    adjustment_quantity: 0,
    unit_cost: product.unit_cost || 0,
    total_cost_change: 0,
    batch_number: "",
    expiry_date: null,
    notes: "",
  };

  // Calculate initial adjustment
  calculateAdjustment(newItem);

  form.items.push(newItem);

  // Clear search
  productSearch.value = "";
  searchResults.value = [];
};

// Remove product from adjustment
const removeProduct = (index) => {
  form.items.splice(index, 1);
};

// Calculate adjustment quantity and cost
const calculateAdjustment = (item) => {
  item.adjustment_quantity = item.actual_quantity - item.system_quantity;
  item.total_cost_change = item.adjustment_quantity * item.unit_cost;
};

// Computed values
const totalValueChange = computed(() => {
  return form.items.reduce(
    (sum, item) => sum + (item.total_cost_change || 0),
    0
  );
});

const reasonOptions = computed(() =>
  Object.entries(props.reasons || {}).map(([key, label]) => ({
    label,
    value: key,
  }))
);

// Submit form
const handleSubmit = async () => {
  if (!form.reason) {
    notification.warning({
      message: "Reason Required",
      description: "Please select a reason for this adjustment",
    });
    return;
  }

  if (form.items.length === 0) {
    notification.warning({
      message: "No Items",
      description: "Please add at least one product to adjust",
    });
    return;
  }

  loading.value = true;

  try {
    // Clean the form data to only send required fields
    const cleanedForm = {
      type: form.type,
      reason: form.reason,
      description: form.description,
      items: form.items.map((item) => ({
        id: item.id, // Include ID for existing items
        product_id: item.product_id,
        actual_quantity: item.actual_quantity,
        unit_cost: item.unit_cost,
        batch_number: item.batch_number || null,
        expiry_date: item.expiry_date || null,
        notes: item.notes || null,
      })),
    };

    const response = await axios.put(
      route("inventory.adjustments.update", props.adjustment.id),
      cleanedForm
    );

    if (response.data.success) {
      notification.success({
        message: "Adjustment Updated",
        description: "Stock adjustment has been updated successfully",
      });
      router.visit(route("inventory.adjustments.index"));
    } else {
      notification.error({
        message: "Update Failed",
        description:
          response.data.message || "Failed to update stock adjustment",
      });
    }
  } catch (error) {
    console.error("Submit error:", error);
    const errorMessage =
      error.response?.data?.message || "An unexpected error occurred";
    notification.error({
      message: "Update Failed",
      description: errorMessage,
    });
  } finally {
    loading.value = false;
  }
};

// Cancel and go back
const handleCancel = () => {
  router.visit(route("inventory.adjustments.index"));
};
</script>

<template>
  <Head title="Edit Stock Adjustment" />

  <AuthenticatedLayout>
    <ContentHeader title="Edit Stock Adjustment" />

    <div class="max-w-6xl mx-auto p-6 space-y-6">
      <!-- Adjustment Info -->
      <a-card class="shadow-sm">
        <div class="flex justify-between items-center">
          <div>
            <h3 class="text-lg font-medium">{{ adjustment.adjustment_number }}</h3>
            <p class="text-sm text-gray-500">
              Created {{ new Date(adjustment.created_at).toLocaleDateString() }}
              by {{ adjustment.created_by?.name }}
            </p>
          </div>
          <a-tag :color="adjustment.status === 'draft' ? 'blue' : 'default'">
            {{ adjustment.status_display }}
          </a-tag>
        </div>
      </a-card>

      <!-- Basic Information -->
      <a-card title="Basic Information" class="shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Location (Read-only for existing adjustments) -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Location
            </label>
            <a-input
              :value="adjustment.location?.name"
              disabled
              class="w-full"
            />
          </div>

          <!-- Reason -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Reason *
            </label>
            <a-select
              v-model:value="form.reason"
              placeholder="Select reason"
              class="w-full"
              :disabled="loading"
            >
              <a-select-option
                v-for="option in reasonOptions"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </a-select-option>
            </a-select>
          </div>

          <!-- Description -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Description
            </label>
            <a-textarea
              v-model:value="form.description"
              placeholder="Optional description or notes about this adjustment"
              :rows="3"
              :disabled="loading"
            />
          </div>
        </div>
      </a-card>

      <!-- Product Search -->
      <a-card title="Add More Products" class="shadow-sm">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Search Products
            </label>
            <div class="relative">
              <a-input
                v-model:value="productSearch"
                placeholder="Search by product name, SKU, or barcode..."
                :disabled="loading"
                class="w-full"
              >
                <template #prefix>
                  <SearchOutlined class="text-gray-400" />
                </template>
              </a-input>

              <!-- Search Results Dropdown -->
              <div
                v-if="searchResults.length > 0"
                class="absolute z-10 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-60 overflow-y-auto"
              >
                <div
                  v-for="product in searchResults"
                  :key="product.id"
                  @click="addProduct(product)"
                  class="px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0"
                >
                  <div class="flex justify-between items-center">
                    <div>
                      <p class="font-medium text-sm">{{ product.name }}</p>
                      <p class="text-xs text-gray-500">
                        SKU: {{ product.SKU || "N/A" }} | Current Stock:
                        {{ product.current_stock || 0 }}
                      </p>
                    </div>
                    <PlusOutlined class="text-blue-500" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </a-card>

      <!-- Adjustment Items -->
      <a-card class="shadow-sm">
        <template #title>
          <div class="flex justify-between items-center">
            <span>Adjustment Items ({{ form.items.length }})</span>
            <div v-if="form.items.length > 0" class="text-sm">
              <span class="text-gray-500">Total Value Change: </span>
              <span
                :class="
                  totalValueChange >= 0 ? 'text-green-600' : 'text-red-600'
                "
                class="font-medium"
              >
                {{ totalValueChange >= 0 ? "+" : "" }}${{
                  Math.abs(totalValueChange).toFixed(2)
                }}
              </span>
            </div>
          </div>
        </template>

        <div
          v-if="form.items.length === 0"
          class="text-center py-8 text-gray-500"
        >
          <p>No products added yet</p>
          <p class="text-sm">
            Search and add products above to create adjustments
          </p>
        </div>

        <div v-else class="space-y-4">
          <!-- Table Header -->
          <div
            class="grid grid-cols-12 gap-4 px-4 py-2 bg-gray-50 rounded-lg text-sm font-medium text-gray-700"
          >
            <div class="col-span-3">Product</div>
            <div class="col-span-2 text-center">System Qty</div>
            <div class="col-span-2 text-center">Actual Qty</div>
            <div class="col-span-2 text-center">Adjustment</div>
            <div class="col-span-2 text-center">Unit Cost</div>
            <div class="col-span-1 text-center">Action</div>
          </div>

          <!-- Adjustment Items -->
          <div
            v-for="(item, index) in form.items"
            :key="item.id || index"
            class="border border-gray-200"
          >
            <div
              class="grid grid-cols-12 gap-4 px-4 py-4 rounded-lg items-center"
            >
              <!-- Product Info -->
              <div class="col-span-3">
                <p class="font-medium text-sm">{{ item.product.name }}</p>
                <p class="text-xs text-gray-500">{{ item.product.SKU }}</p>
              </div>

              <!-- System Quantity (Read-only) -->
              <div class="col-span-2 text-center">
                <div class="bg-gray-50 px-3 py-2 rounded text-sm font-medium">
                  {{ item.system_quantity }}
                </div>
              </div>

              <!-- Actual Quantity (Editable) -->
              <div class="col-span-2">
                <a-input-number
                  v-model:value="item.actual_quantity"
                  :min="0"
                  :step="1"
                  :precision="0"
                  class="w-full"
                  :disabled="loading"
                  @change="calculateAdjustment(item)"
                />
              </div>

              <!-- Adjustment Quantity (Calculated) -->
              <div class="col-span-2 text-center">
                <div
                  :class="[
                    'px-3 py-2 rounded text-sm font-medium',
                    item.adjustment_quantity > 0
                      ? 'bg-green-50 text-green-700'
                      : item.adjustment_quantity < 0
                      ? 'bg-red-50 text-red-700'
                      : 'bg-gray-50 text-gray-700',
                  ]"
                >
                  {{ item.adjustment_quantity >= 0 ? "+" : ""
                  }}{{ item.adjustment_quantity }}
                </div>
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
                  @change="calculateAdjustment(item)"
                />
              </div>

              <!-- Remove Button -->
              <div class="col-span-1 text-center">
                <a-button
                  type="text"
                  danger
                  @click="removeProduct(index)"
                  :disabled="loading"
                >
                  <template #icon>
                    <MinusOutlined />
                  </template>
                </a-button>
              </div>
            </div>

            <!-- Additional Details (Full Width) -->
            <div
              class="px-4 col-span-12 pb-4 grid grid-cols-1 md:grid-cols-2 gap-4"
            >
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">
                  Batch Number
                </label>
                <a-input
                  v-model:value="item.batch_number"
                  placeholder="Optional"
                  size="small"
                  :disabled="loading"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">
                  Expiry Date
                </label>
                <a-date-picker
                  v-model:value="item.expiry_date"
                  placeholder="Optional"
                  size="small"
                  class="w-full"
                  :disabled="loading"
                />
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">
                  Notes
                </label>
                <a-input
                  v-model:value="item.notes"
                  placeholder="Optional notes for this item"
                  size="small"
                  :disabled="loading"
                />
              </div>
            </div>
          </div>
        </div>
      </a-card>

      <!-- Summary -->
      <a-card v-if="form.items.length > 0" title="Summary" class="shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div class="text-center">
            <div class="text-2xl font-bold text-blue-600">
              {{ form.items.length }}
            </div>
            <div class="text-sm text-gray-500">Products</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold text-green-600">
              {{
                form.items.filter((item) => item.adjustment_quantity > 0).length
              }}
            </div>
            <div class="text-sm text-gray-500">Increases</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold text-red-600">
              {{
                form.items.filter((item) => item.adjustment_quantity < 0).length
              }}
            </div>
            <div class="text-sm text-gray-500">Decreases</div>
          </div>
        </div>
      </a-card>

      <!-- Action Buttons -->
      <div class="flex justify-end space-x-4 pt-6 border-t">
        <a-button @click="handleCancel">
          <template #icon>
            <ArrowLeftOutlined />
          </template>
          Back
        </a-button>
        <a-button
          type="primary"
          @click="handleSubmit"
          :loading="loading"
          class="bg-green-700 text-white border flex items-center border-green-500"
        >
          <template #icon>
            <SaveOutlined />
          </template>
          Update Adjustment
        </a-button>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
