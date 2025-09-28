<script setup>
import { ref, reactive, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { SearchOutlined, SwapOutlined } from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import axios from "axios";

const { openModal } = useGlobalVariables();
const { showNotification } = useHelpers();

const emit = defineEmits(["success", "update:visible"]);

const props = defineProps({
  locations: Array,
  currentLocation: Object,
  visible: Boolean,
});

// Form state
const form = reactive({
  product_id: null,
  from_location_id: null,
  to_location_id: null,
  quantity: 1,
  notes: '',
});

const loading = ref(false);
const productSearch = ref("");
const searchResults = ref([]);
const searchLoading = ref(false);
const selectedProduct = ref(null);
const availableStock = ref(0);

// Initialize form
const initializeForm = () => {
  form.product_id = null;
  form.from_location_id = props.currentLocation?.id || null;
  form.to_location_id = null;
  form.quantity = 1;
  form.notes = '';
  productSearch.value = "";
  searchResults.value = [];
  selectedProduct.value = null;
  availableStock.value = 0;
};

// Available locations for transfer (exclude from_location)
const availableToLocations = computed(() => {
  return props.locations?.filter(loc => loc.id !== form.from_location_id) || [];
});

// Product search
const searchProducts = async () => {
  if (!productSearch.value || productSearch.value.length < 2) {
    searchResults.value = [];
    return;
  }

  searchLoading.value = true;
  try {
    const response = await axios.get('/api/sales/products', {
      params: { search: productSearch.value }
    });
    searchResults.value = response.data.data || [];
  } catch (error) {
    console.error('Product search error:', error);
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
    const response = await axios.get('/api/inventory/products', {
      params: {
        location_id: form.from_location_id,
        search: selectedProduct.value?.SKU || selectedProduct.value?.name
      }
    });
    
    const inventory = response.data.data?.find(inv => inv.product_id === form.product_id);
    availableStock.value = inventory?.quantity_available || 0;
  } catch (error) {
    console.error('Error getting available stock:', error);
    availableStock.value = 0;
  }
};

// Watch from_location change to update available stock
watch(() => form.from_location_id, () => {
  if (form.product_id) {
    getAvailableStock();
  }
  // Reset to_location if it's the same as from_location
  if (form.to_location_id === form.from_location_id) {
    form.to_location_id = null;
  }
});

// Submit form
const handleSubmit = async () => {
  if (!form.product_id) {
    showNotification('warning', 'No Product Selected', 'Please select a product to transfer');
    return;
  }

  if (!form.from_location_id || !form.to_location_id) {
    showNotification('warning', 'Missing Locations', 'Please select both from and to locations');
    return;
  }

  if (form.quantity <= 0) {
    showNotification('warning', 'Invalid Quantity', 'Please enter a valid quantity');
    return;
  }

  if (form.quantity > availableStock.value) {
    showNotification('warning', 'Insufficient Stock', `Only ${availableStock.value} units available`);
    return;
  }

  loading.value = true;
  
  try {
    await router.post('/api/inventory/transfer', form, {
      onSuccess: () => {
        showNotification('success', 'Success', 'Inventory transferred successfully');
        closeModal();
        emit('success');
      },
      onError: (errors) => {
        console.error('Transfer inventory errors:', errors);
        showNotification('error', 'Error', 'Failed to transfer inventory');
      },
    });
  } catch (error) {
    console.error('Submit error:', error);
    showNotification('error', 'Error', 'An unexpected error occurred');
  } finally {
    loading.value = false;
  }
};

// Close modal
const closeModal = () => {
  emit('update:visible', false);
  initializeForm();
};

// Initialize when modal opens
watch(() => props.visible, (isOpen) => {
  if (isOpen) {
    initializeForm();
  }
});
</script>

<template>
  <a-modal
    :open="visible"
    title="Transfer Inventory"
    width="600px"
    :confirm-loading="loading"
    @ok="handleSubmit"
    @cancel="closeModal"
  >
    <div class="space-y-6">
      <!-- Product Selection -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Select Product *
        </label>
        <div class="relative">
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
                  <p class="text-sm font-medium">₱{{ product.price?.toFixed(2) || '0.00' }}</p>
                  <p class="text-xs text-gray-500">{{ product.category?.name || 'No Category' }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Selected Product Display -->
        <div v-if="selectedProduct" class="mt-2 p-3 bg-blue-50 rounded-lg">
          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-blue-900">{{ selectedProduct.name }}</p>
              <p class="text-sm text-blue-700">SKU: {{ selectedProduct.SKU }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm font-medium text-blue-900">Available: {{ availableStock }}</p>
              <p class="text-xs text-blue-700">{{ selectedProduct.unit_of_measure || 'pcs' }}</p>
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
              {{ location.name }}
            </a-select-option>
          </a-select>
        </div>
      </div>

      <!-- Transfer Arrow -->
      <div v-if="form.from_location_id && form.to_location_id" class="text-center">
        <div class="inline-flex items-center space-x-4 p-4 bg-gray-50 rounded-lg">
          <div class="text-center">
            <p class="font-medium text-gray-900">
              {{ locations?.find(l => l.id === form.from_location_id)?.name }}
            </p>
            <p class="text-sm text-gray-500">From</p>
          </div>
          
          <SwapOutlined class="text-2xl text-blue-600" />
          
          <div class="text-center">
            <p class="font-medium text-gray-900">
              {{ locations?.find(l => l.id === form.to_location_id)?.name }}
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
          :min="0.001"
          :max="availableStock"
          :step="1"
          :precision="3"
          class="w-full"
          :disabled="loading || !selectedProduct"
        />
        <p v-if="availableStock > 0" class="text-sm text-gray-500 mt-1">
          Maximum available: {{ availableStock }} {{ selectedProduct?.unit_of_measure || 'pcs' }}
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
      <div v-if="selectedProduct && availableStock <= 0" class="p-4 bg-red-50 border border-red-200 rounded-lg">
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
            {{ selectedProduct.name }} • {{ form.quantity }} {{ selectedProduct.unit_of_measure || 'pcs' }}
          </span>
        </div>
        <div class="space-x-2">
          <a-button @click="closeModal" :disabled="loading">
            Cancel
          </a-button>
          <a-button 
            type="primary" 
            @click="handleSubmit" 
            :loading="loading"
            :disabled="!selectedProduct || !form.from_location_id || !form.to_location_id || form.quantity <= 0 || form.quantity > availableStock"
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
