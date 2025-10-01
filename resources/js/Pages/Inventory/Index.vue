<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import {
  ShoppingCartOutlined,
  WarningOutlined,
  StopOutlined,
  DollarOutlined,
  BoxPlotOutlined,
  HistoryOutlined,
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { watchDebounced } from "@vueuse/core";
import { useHelpers } from "@/Composables/useHelpers";
import VueApexCharts from "vue3-apexcharts";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";

const page = usePage();
const { spinning } = useGlobalVariables();
const { formattedTotal } = useHelpers();

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
    selectedLocation.value =
      props.filters.location_id || props.currentLocation?.id || null;
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
const locationFilterOptions = computed(
  () =>
    props.locations?.map((loc) => ({
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
      getLabel: toLabel(computed(() => locationFilterOptions.value)),
    },
  ],
});

// FilterDropdown configuration
const filtersConfig = [
  {
    key: "location_id",
    label: "Location",
    type: "select",
    options: locationFilterOptions.value,
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
  router.visit(route("inventory.products"), {
    data: { location_id: selectedLocation.value },
  });
};

const navigateToMovements = () => {
  router.visit(route("inventory.movements"), {
    data: { location_id: selectedLocation.value },
  });
};

const navigateToAdjustments = () => {
  router.visit(route("inventory.adjustments.index"));
};

// Chart data for stock levels by category
const stockLevelChart = computed(() => {
  // Sample data - in real implementation, this would come from props or API
  const categories = [
    { name: 'Electronics', inStock: 45, lowStock: 8, outOfStock: 2 },
    { name: 'Clothing', inStock: 32, lowStock: 5, outOfStock: 1 },
    { name: 'Home & Garden', inStock: 28, lowStock: 3, outOfStock: 0 },
    { name: 'Sports', inStock: 15, lowStock: 2, outOfStock: 1 },
    { name: 'Books', inStock: 22, lowStock: 4, outOfStock: 0 },
  ];

  return {
    series: [
      {
        name: 'In Stock',
        data: categories.map(cat => cat.inStock),
        color: '#10B981'
      },
      {
        name: 'Low Stock',
        data: categories.map(cat => cat.lowStock),
        color: '#F59E0B'
      },
      {
        name: 'Out of Stock',
        data: categories.map(cat => cat.outOfStock),
        color: '#EF4444'
      }
    ],
    chartOptions: {
      chart: {
        type: 'bar',
        height: 300,
        stacked: true,
        toolbar: {
          show: false
        }
      },
      plotOptions: {
        bar: {
          horizontal: false,
          columnWidth: '60%',
          borderRadius: 4,
        }
      },
      dataLabels: {
        enabled: false
      },
      xaxis: {
        categories: categories.map(cat => cat.name),
        labels: {
          style: {
            fontSize: '12px',
            fontFamily: 'Inter, sans-serif',
          }
        }
      },
      yaxis: {
        title: {
          text: 'Number of Products',
          style: {
            fontSize: '12px',
            fontFamily: 'Inter, sans-serif',
          }
        },
        labels: {
          style: {
            fontSize: '11px',
            fontFamily: 'Inter, sans-serif',
          }
        }
      },
      legend: {
        position: 'top',
        fontSize: '12px',
        fontFamily: 'Inter, sans-serif',
        markers: {
          width: 8,
          height: 8,
          radius: 4
        }
      },
      colors: ['#10B981', '#F59E0B', '#EF4444'],
      grid: {
        borderColor: '#f1f1f1',
        strokeDashArray: 3
      },
      responsive: [{
        breakpoint: 768,
        options: {
          chart: {
            height: 250
          },
          legend: {
            position: 'bottom'
          }
        }
      }]
    }
  };
});

// Register ApexCharts component
const apexchart = VueApexCharts;
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
          <div
            class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-blue-400/10 to-blue-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 mr-4">
                  <BoxPlotOutlined class="text-2xl text-blue-600" />
                </div>
                <div>
                  <p class="text-sm text-blue-600 font-medium">
                    Total Products
                  </p>
                  <p class="text-2xl font-bold text-blue-800">
                    {{ summary.total_products || 0 }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- In Stock Products -->
          <div
            class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-green-400/10 to-green-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 mr-4">
                  <ShoppingCartOutlined class="text-2xl text-green-600" />
                </div>
                <div>
                  <p class="text-sm text-green-600 font-medium">In Stock</p>
                  <p class="text-2xl font-bold text-green-800">
                    {{ summary.in_stock_products || 0 }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Low Stock Products -->
          <div
            class="bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-orange-400/10 to-orange-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center">
                <div class="p-3 rounded-full bg-orange-100 mr-4">
                  <WarningOutlined class="text-2xl text-orange-600" />
                </div>
                <div>
                  <p class="text-sm text-orange-600 font-medium">Low Stock</p>
                  <p class="text-2xl font-bold text-orange-800">
                    {{ summary.low_stock_products || 0 }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Out of Stock Products -->
          <div
            class="bg-gradient-to-br from-red-50 to-red-100 border border-red-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-red-400/10 to-red-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center">
                <div class="p-3 rounded-full bg-red-100 mr-4">
                  <StopOutlined class="text-2xl text-red-600" />
                </div>
                <div>
                  <p class="text-sm text-red-600 font-medium">Out of Stock</p>
                  <p class="text-2xl font-bold text-red-800">
                    {{ summary.out_of_stock_products || 0 }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Combined Inventory Value and Chart Card -->
        <div class="mb-6 px-6">
          <div class="bg-white border border-gray-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300">
            <!-- Header Section -->
            <div class="flex items-center justify-between mb-6">
              <div class="flex items-center">
                <div class="p-3 rounded-full bg-indigo-100 mr-4">
                  <BoxPlotOutlined class="text-2xl text-indigo-600" />
                </div>
                <div>
                  <h3 class="text-lg font-semibold text-gray-800">
                    {{ location.name || "All Locations" }}
                  </h3>
                  <p class="text-sm text-gray-600">
                    {{
                      location.type
                        ? location.type.charAt(0).toUpperCase() +
                          location.type.slice(1)
                        : ""
                    }}
                    {{ location.address ? " • " + location.address : "" }}
                  </p>
                  <div class="mt-2">
                    <p class="text-sm text-gray-600">
                      Location Code:
                      <span class="font-semibold text-indigo-600">
                        {{ location.code || "ALL" }}
                      </span>
                    </p>
                  </div>
                </div>
              </div>
              <div class="text-right">
                <p class="text-sm text-gray-600 font-medium">
                  Total Inventory Value
                </p>
                <p class="text-3xl font-bold text-gray-800">
                  {{ formattedTotal(summary.total_inventory_value) }}
                </p>
                <a-button
                  type="primary"
                  class="bg-purple-600 border-purple-600 hover:bg-purple-700 mt-2"
                  @click="router.visit(route('inventory.valuation'))"
                >
                  View Report
                </a-button>
              </div>
            </div>

            <!-- Chart Section -->
            <div class="border-t border-gray-100 pt-6">
              <div class="flex items-center justify-between mb-4">
                <h4 class="text-md font-semibold text-gray-800">Stock Level by Category</h4>
                <p class="text-sm text-gray-600">Inventory distribution across categories</p>
              </div>
              <div class="w-full">
                <apexchart
                  type="bar"
                  height="300"
                  width="500"
                  :options="stockLevelChart.chartOptions"
                  :series="stockLevelChart.series"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 px-6">
          <div
            class="bg-gray-50 border border-gray-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 cursor-pointer"
            @click="navigateToProducts"
          >
            <div class="text-center">
              <div
                class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mx-auto mb-4"
              >
                <BoxPlotOutlined class="text-2xl text-blue-600" />
              </div>
              <h3 class="text-lg font-semibold mb-2 text-gray-800">
                Manage Products
              </h3>
              <p class="text-gray-600 text-sm">
                View and manage product inventory levels
              </p>
            </div>
          </div>

          <div
            class="bg-gray-50 border border-gray-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 cursor-pointer"
            @click="navigateToMovements"
          >
            <div class="text-center">
              <div
                class="w-16 h-16 bg-green-100 rounded-xl flex items-center justify-center mx-auto mb-4"
              >
                <HistoryOutlined class="text-2xl text-green-600" />
              </div>
              <h3 class="text-lg font-semibold mb-2 text-gray-800">
                Inventory Movements
              </h3>
              <p class="text-gray-600 text-sm">
                Track all inventory transactions
              </p>
            </div>
          </div>

          <div
            class="bg-gray-50 border border-gray-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 cursor-pointer"
            @click="navigateToAdjustments"
          >
            <div class="text-center">
              <div
                class="w-16 h-16 bg-orange-100 rounded-xl flex items-center justify-center mx-auto mb-4"
              >
                <WarningOutlined class="text-2xl text-orange-600" />
              </div>
              <h3 class="text-lg font-semibold mb-2 text-gray-800">
                Stock Adjustments
              </h3>
              <p class="text-gray-600 text-sm">
                Create and manage stock adjustments
              </p>
            </div>
          </div>
          
        </div>

        <div v-if="lowStockProducts.length > 0" class="w-full p-6">
            <div
              class="bg-white border border-orange-200 p-6 rounded-lg hover:shadow-lg transition-all duration-300 h-full"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center">
                  <div class="p-2 rounded-full bg-orange-100 mr-3">
                    <WarningOutlined class="text-orange-600 text-lg" />
                  </div>
                  <div>
                    <h3 class="text-lg font-semibold text-gray-800">
                      Low Stock Alert
                    </h3>
                    <p class="text-sm text-gray-600">
                      ⚠️ Items running low — check restocking needs
                    </p>
                  </div>
                </div>
                <div class="text-right">
                  <p class="text-2xl font-bold text-orange-600">
                    {{ lowStockProducts.length }}
                  </p>
                  <p class="text-sm text-gray-600">items need attention</p>
                  <a-button
                    type="link"
                    @click="navigateToProducts"
                    class="text-orange-600 hover:text-orange-700 text-sm p-0"
                  >
                    View All →
                  </a-button>
                </div>
              </div>
            </div>
          </div>
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>

<style scoped>
/* Enhanced hover effects */
.hover\:shadow-lg:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1),
    0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

/* Smooth transitions */
* {
  transition: all 0.3s ease;
}
</style>