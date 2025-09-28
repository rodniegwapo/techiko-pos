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

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";

const page = usePage();
const { spinning } = useGlobalVariables();

const selectedLocation = ref(null);

// Props from backend
const props = defineProps({
  report: Object,
  locations: Array,
  currentLocation: Object,
});

// Computed values
const summary = computed(() => props.report?.summary || {});
const location = computed(() => props.report?.location || {});
const lowStockProducts = computed(() => props.report?.low_stock_products || []);

// Methods
const refreshData = () => {
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

const changeLocation = (locationId) => {
  selectedLocation.value = locationId;
  refreshData();
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

onMounted(() => {
  selectedLocation.value = props.currentLocation?.id || null;
});
</script>

<template>
  <Head title="Inventory Dashboard" />

  <AuthenticatedLayout>
  
    <ContentHeader title="Inventory Dashboard">
      <template #actions>
        <a-select
          v-model:value="selectedLocation"
          placeholder="Select Location"
          style="width: 200px; margin-right: 8px"
          @change="changeLocation"
        >
          <a-select-option 
            v-for="loc in locations" 
            :key="loc.id" 
            :value="loc.id"
          >
            {{ loc.name }}
          </a-select-option>
        </a-select>
        <RefreshButton @click="refreshData" />
      </template>
    </ContentHeader>

    <ContentLayout>
      <!-- Location Info -->
      <div class="mb-6">
        <a-card>
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-lg font-semibold text-gray-800">
                {{ location.name || 'All Locations' }}
              </h3>
              <p class="text-sm text-gray-600">
                {{ location.type ? location.type.charAt(0).toUpperCase() + location.type.slice(1) : '' }}
                {{ location.address ? ' • ' + location.address : '' }}
              </p>
            </div>
            <div class="text-right">
              <p class="text-sm text-gray-600">Location Code</p>
              <p class="text-lg font-semibold">{{ location.code || 'ALL' }}</p>
            </div>
          </div>
        </a-card>
      </div>

      <!-- Summary Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Total Products -->
        <a-card class="hover:shadow-lg transition-shadow cursor-pointer" @click="navigateToProducts">
          <div class="flex items-center">
            <div class="p-3 rounded-full bg-blue-100 mr-4">
              <BoxPlotOutlined class="text-2xl text-blue-600" />
            </div>
            <div>
              <p class="text-sm text-gray-600">Total Products</p>
              <p class="text-2xl font-bold text-gray-800">{{ summary.total_products || 0 }}</p>
            </div>
          </div>
        </a-card>

        <!-- In Stock Products -->
        <a-card class="hover:shadow-lg transition-shadow cursor-pointer" @click="navigateToProducts">
          <div class="flex items-center">
            <div class="p-3 rounded-full bg-green-100 mr-4">
              <ShoppingCartOutlined class="text-2xl text-green-600" />
            </div>
            <div>
              <p class="text-sm text-gray-600">In Stock</p>
              <p class="text-2xl font-bold text-green-600">{{ summary.in_stock_products || 0 }}</p>
            </div>
          </div>
        </a-card>

        <!-- Low Stock Products -->
        <a-card class="hover:shadow-lg transition-shadow cursor-pointer" @click="navigateToProducts">
          <div class="flex items-center">
            <div class="p-3 rounded-full bg-yellow-100 mr-4">
              <WarningOutlined class="text-2xl text-yellow-600" />
            </div>
            <div>
              <p class="text-sm text-gray-600">Low Stock</p>
              <p class="text-2xl font-bold text-yellow-600">{{ summary.low_stock_products || 0 }}</p>
            </div>
          </div>
        </a-card>

        <!-- Out of Stock Products -->
        <a-card class="hover:shadow-lg transition-shadow cursor-pointer" @click="navigateToProducts">
          <div class="flex items-center">
            <div class="p-3 rounded-full bg-red-100 mr-4">
              <StopOutlined class="text-2xl text-red-600" />
            </div>
            <div>
              <p class="text-sm text-gray-600">Out of Stock</p>
              <p class="text-2xl font-bold text-red-600">{{ summary.out_of_stock_products || 0 }}</p>
            </div>
          </div>
        </a-card>
      </div>

      <!-- Inventory Value Card -->
      <div class="mb-6">
        <a-card>
          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <div class="p-3 rounded-full bg-purple-100 mr-4">
                <DollarOutlined class="text-2xl text-purple-600" />
              </div>
              <div>
                <p class="text-sm text-gray-600">Total Inventory Value</p>
                <p class="text-3xl font-bold text-purple-600">
                  ₱{{ (summary.total_inventory_value || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
                </p>
              </div>
            </div>
            <a-button type="primary" @click="router.visit(route('inventory.valuation'))">
              View Valuation Report
            </a-button>
          </div>
        </a-card>
      </div>

      <!-- Quick Actions -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <a-card class="hover:shadow-lg transition-shadow cursor-pointer" @click="navigateToProducts">
          <div class="text-center">
            <BoxPlotOutlined class="text-4xl text-blue-600 mb-2" />
            <h3 class="text-lg font-semibold mb-2">Manage Products</h3>
            <p class="text-gray-600">View and manage product inventory levels</p>
          </div>
        </a-card>

        <a-card class="hover:shadow-lg transition-shadow cursor-pointer" @click="navigateToMovements">
          <div class="text-center">
            <HistoryOutlined class="text-4xl text-green-600 mb-2" />
            <h3 class="text-lg font-semibold mb-2">Inventory Movements</h3>
            <p class="text-gray-600">Track all inventory transactions</p>
          </div>
        </a-card>

        <a-card class="hover:shadow-lg transition-shadow cursor-pointer" @click="navigateToAdjustments">
          <div class="text-center">
            <WarningOutlined class="text-4xl text-orange-600 mb-2" />
            <h3 class="text-lg font-semibold mb-2">Stock Adjustments</h3>
            <p class="text-gray-600">Create and manage stock adjustments</p>
          </div>
        </a-card>
      </div>

      <!-- Low Stock Alert -->
      <div v-if="lowStockProducts.length > 0" class="mb-6">
        <a-card>
          <template #title>
            <div class="flex items-center">
              <WarningOutlined class="text-yellow-600 mr-2" />
              Low Stock Alert ({{ lowStockProducts.length }} items)
            </div>
          </template>
          <template #extra>
            <a-button type="link" @click="navigateToProducts">View All</a-button>
          </template>
          
          <div class="space-y-3">
            <div 
              v-for="product in lowStockProducts.slice(0, 5)" 
              :key="product.id"
              class="flex items-center justify-between p-3 bg-yellow-50 rounded-lg"
            >
              <div>
                <p class="font-semibold text-gray-800">{{ product.name }}</p>
                <p class="text-sm text-gray-600">SKU: {{ product.SKU }}</p>
              </div>
              <div class="text-right">
                <p class="text-sm text-gray-600">Current Stock</p>
                <p class="text-lg font-bold text-yellow-600">
                  {{ product.total_available_quantity || 0 }}
                </p>
                <p class="text-xs text-gray-500">
                  Reorder at: {{ product.reorder_level }}
                </p>
              </div>
            </div>
            
            <div v-if="lowStockProducts.length > 5" class="text-center pt-3">
              <a-button type="link" @click="navigateToProducts">
                View {{ lowStockProducts.length - 5 }} more items
              </a-button>
            </div>
          </div>
        </a-card>
      </div>
    </ContentLayout>
  </AuthenticatedLayout>
</template>

<style scoped>
.ant-card {
  border-radius: 8px;
}

.ant-card-body {
  padding: 20px;
}
</style>
