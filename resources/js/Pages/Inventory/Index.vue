<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { 
  ShoppingCartOutlined, 
  WarningOutlined, 
  StopOutlined,
  DollarOutlined,
  BoxPlotOutlined,
  HistoryOutlined
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { watchDebounced } from "@vueuse/core";
import { useHelpers } from "@/Composables/useHelpers";  

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";

const page = usePage();
const { spinning } = useGlobalVariables();
const {formattedTotal} = useHelpers()

const selectedLocation = ref(null);

// Props from backend
const props = defineProps({
  report: Object,
  locations: Array,
  currentLocation: Object,
  filters: Object,
});

// Initialize filters from backend
onMounted(() => {
  if (props.filters) {
    selectedLocation.value = props.filters.location_id || props.currentLocation?.id || null;
  }
});

// Computed values
const summary = computed(() => props.report?.summary || {});
const location = computed(() => props.report?.location || {});
const lowStockProducts = computed(() => props.report?.low_stock_products || []);

// Fetch items
const getItems = () => {
  router.reload({
    only: ["report"],
    preserveScroll: true,
    data: {
      location_id: selectedLocation.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};


// Filter options
const locationOptions = computed(() => 
  props.locations?.map(loc => ({
    label: loc.name,
    value: loc.id,
  })) || []
);

// Filter management
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
      label: "Location",
      key: "location_id",
      ref: selectedLocation,
      getLabel: toLabel(computed(() => locationOptions.value)),
    },
  ],
});

// FilterDropdown configuration
const filtersConfig = [
  {
    key: "location_id",
    label: "Location",
    type: "select",
    options: locationOptions.value,
  },
];

// Methods
const refreshData = () => {
  getItems();
};

const changeLocation = (locationId) => {
  selectedLocation.value = locationId;
  getItems();
};

const navigateToProducts = () => {
  router.visit(route('inventory.products'), {
    data: { location_id: selectedLocation.value }
  });
};

const navigateToMovements = () => {
  router.visit(route('inventory.movements'), {
    data: { location_id: selectedLocation.value }
  });
};

const navigateToAdjustments = () => {
  router.visit(route('inventory.adjustments.index'));
};
</script>

<template>
  <Head title="Inventory Dashboard" />

  <AuthenticatedLayout>
    <ContentHeader title="Inventory Dashboard" />
    
    <ContentLayout title="Inventory Overview">
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <FilterDropdown v-model="filters" :filters="filtersConfig" />
      </template>

      <template #activeFilters>
        <ActiveFilters
          :filters="activeFilters"
          @remove-filter="handleClearSelectedFilter"
          @clear-all="
            () => Object.keys(filters).forEach((k) => (filters[k] = null))
          "
        />
        
        <!-- Current Location Info -->
        <div v-if="location.name" class="mb-4">
          <a-alert
            :message="`Viewing inventory for: ${location.name}`"
            :description="location.address"
            type="info"
            show-icon
            closable
          />
        </div>
      </template>

      <template #table>
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 p-6">
          <!-- Total Products -->
          <a-card class="bg-gradient-to-br from-blue-50 to-blue-100 border-blue-200 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 mr-4 shadow-lg">
                <BoxPlotOutlined class="text-2xl text-white" />
              </div>
              <div>
                <p class="text-sm text-blue-700 font-medium">Total Products</p>
                <p class="text-2xl font-bold text-blue-800">{{ summary.total_products || 0 }}</p>
              </div>
            </div>
          </a-card>

          <!-- In Stock Products -->
          <a-card class="bg-gradient-to-br from-green-50 to-green-100 border-green-200 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-gradient-to-r from-green-500 to-green-600 mr-4 shadow-lg">
                <ShoppingCartOutlined class="text-2xl text-white" />
              </div>
              <div>
                <p class="text-sm text-green-700 font-medium">In Stock</p>
                <p class="text-2xl font-bold text-green-800">{{ summary.in_stock_products || 0 }}</p>
              </div>
            </div>
          </a-card>

          <!-- Low Stock Products -->
          <a-card class="bg-gradient-to-br from-amber-50 to-amber-100 border-amber-200 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-gradient-to-r from-amber-500 to-amber-600 mr-4 shadow-lg">
                <WarningOutlined class="text-2xl text-white" />
              </div>
              <div>
                <p class="text-sm text-amber-700 font-medium">Low Stock</p>
                <p class="text-2xl font-bold text-amber-800">{{ summary.low_stock_products || 0 }}</p>
              </div>
            </div>
          </a-card>

          <!-- Out of Stock Products -->
          <a-card class="bg-gradient-to-br from-red-50 to-red-100 border-red-200 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-gradient-to-r from-red-500 to-red-600 mr-4 shadow-lg">
                <StopOutlined class="text-2xl text-white" />
              </div>
              <div>
                <p class="text-sm text-red-700 font-medium">Out of Stock</p>
                <p class="text-2xl font-bold text-red-800">{{ summary.out_of_stock_products || 0 }}</p>
              </div>
            </div>
          </a-card>
        </div>

        <!-- Location Info and Inventory Value -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6 px-6">
          <!-- Location Info -->
          <div class=" p-6 border rounded-lg bg-gradient-to hover:shadow-lg transition-all duration-300">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-lg font-semibold text-indigo-800">
                  {{ location.name || 'All Locations' }}
                </h3>
                <p class="text-sm text-indigo-600">
                  {{ location.type ? location.type.charAt(0).toUpperCase() + location.type.slice(1) : '' }}
                  {{ location.address ? ' • ' + location.address : '' }}
                </p>
                <div class="mt-2">
                  <p class="text-sm text-indigo-600">Location Code</p>
                  <p class="text-lg font-semibold text-indigo-800">{{ location.code || 'ALL' }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Inventory Value Card -->
          <div class="p-6 rounded-lg border bg-gradient-to-br from-purple-50 to-purple-100 border-purple-200 hover:shadow-lg transition-all duration-300">
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <div class="p-3 rounded-full bg-gradient-to-r from-purple-500 to-purple-600 mr-4 shadow-lg">
                  <DollarOutlined class="text-2xl text-white" />
                </div>
                <div>
                  <p class="text-sm text-purple-700 font-medium">Total Inventory Value</p>
                  <p class="text-3xl font-bold text-purple-800">
                  
                    {{ formattedTotal(summary.total_inventory_value ) }}
                  </p>
                </div>
              </div>
              <a-button type="primary" class="bg-gradient-to-r from-purple-500 to-purple-600 border-purple-500 hover:from-purple-600 hover:to-purple-700" @click="router.visit(route('inventory.valuation'))">
                View Report
              </a-button>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 px-6">
          <a-card class="bg-gradient-to-br from-cyan-50 to-cyan-100 border-cyan-200 hover:shadow-lg transition-all duration-300 cursor-pointer" @click="navigateToProducts">
            <div class="text-center">
              <div class="w-16 h-16 bg-gradient-to-r from-cyan-500 to-cyan-600 rounded-xl flex items-center justify-center shadow-lg mx-auto mb-4">
                <BoxPlotOutlined class="text-2xl text-white" />
              </div>
              <h3 class="text-lg font-semibold mb-2 text-cyan-800">Manage Products</h3>
              <p class="text-cyan-600 text-sm">View and manage product inventory levels</p>
            </div>
          </a-card>

          <a-card class="bg-gradient-to-br from-emerald-50 to-emerald-100 border-emerald-200 hover:shadow-lg transition-all duration-300 cursor-pointer" @click="navigateToMovements">
            <div class="text-center">
              <div class="w-16 h-16 bg-gradient-to-r from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg mx-auto mb-4">
                <HistoryOutlined class="text-2xl text-white" />
              </div>
              <h3 class="text-lg font-semibold mb-2 text-emerald-800">Inventory Movements</h3>
              <p class="text-emerald-600 text-sm">Track all inventory transactions</p>
            </div>
          </a-card>

          <a-card class="bg-gradient-to-br from-orange-50 to-orange-100 border-orange-200 hover:shadow-lg transition-all duration-300 cursor-pointer" @click="navigateToAdjustments">
            <div class="text-center">
              <div class="w-16 h-16 bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl flex items-center justify-center shadow-lg mx-auto mb-4">
                <WarningOutlined class="text-2xl text-white" />
              </div>
              <h3 class="text-lg font-semibold mb-2 text-orange-800">Stock Adjustments</h3>
              <p class="text-orange-600 text-sm">Create and manage stock adjustments</p>
            </div>
          </a-card>
        </div>

        <!-- Low Stock Alert -->
        <div v-if="lowStockProducts.length > 0" class="mb-6 px-6">
          <a-card class="bg-gradient-to-br from-amber-50 to-amber-100 border-amber-300 shadow-lg">
            <template #title>
              <div class="flex items-center justify-between">
                <div class="flex items-center">
                  <div class="p-2 rounded-full bg-gradient-to-r from-amber-500 to-amber-600 mr-3 shadow-lg">
                    <WarningOutlined class="text-amber-100 text-lg" />
                  </div>
                  <span class="text-amber-800 font-semibold text-lg">
                    Low Stock Alert ({{ lowStockProducts.length }} items)
                  </span>
                </div>
                <a-button type="link" @click="navigateToProducts" class="text-amber-600 hover:text-amber-700 font-medium">
                  View All
                </a-button>
              </div>
            </template>
            
            <div class="space-y-3">
              <div 
                v-for="product in lowStockProducts.slice(0, 5)" 
                :key="product.id"
                class="flex items-center justify-between p-4 bg-gradient-to-r from-amber-100 to-amber-200 rounded-lg border border-amber-300 shadow-sm"
              >
                <div>
                  <p class="font-semibold text-amber-800">{{ product.name }}</p>
                  <p class="text-sm text-amber-600">SKU: {{ product.SKU }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm text-amber-600">Current Stock</p>
                  <p class="text-lg font-bold text-amber-800">
                    {{ product.total_available_quantity || 0 }}
                  </p>
                  <p class="text-xs text-amber-500">
                    Reorder at: {{ product.reorder_level }}
                  </p>
                </div>
              </div>
              
              <div v-if="lowStockProducts.length > 5" class="text-center pt-3">
                <a-button type="link" @click="navigateToProducts" class="text-amber-600 hover:text-amber-700 font-medium">
                  View {{ lowStockProducts.length - 5 }} more items
                </a-button>
              </div>
            </div>
          </a-card>
        </div>
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>

<style scoped>
.ant-card {
  border-radius: 12px;
  border-width: 1px;
}

.ant-card-body {
  padding: 24px;
}

/* Enhanced hover effects */
.ant-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

/* Gradient text effects */
.gradient-text {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

/* Enhanced shadow effects */
.shadow-luxury {
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25),
    0 0 0 1px rgba(255, 255, 255, 0.05);
}

/* Smooth transitions */
* {
  transition: all 0.3s ease;
}
</style>