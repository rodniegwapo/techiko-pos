<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { PlusSquareOutlined, DownloadOutlined } from "@ant-design/icons-vue";
import { watchDebounced } from "@vueuse/core";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useTable } from "@/Composables/useTable";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import InventoryProductTable from "./components/InventoryProductTable.vue";
import ReceiveInventoryModal from "./components/ReceiveInventoryModal.vue";
import TransferInventoryModal from "./components/TransferInventoryModal.vue";
import ProductDetailsModal from "./components/ProductDetailsModal.vue";

const page = usePage();
const { showModal } = useHelpers();
const { spinning } = useGlobalVariables();

const search = ref("");
const stock_status = ref(null);
const location_id = ref(null);

// Props from backend
const props = defineProps({
  inventories: Object,
  locations: Array,
  currentLocation: Object,
  categories: Array,
  filters: Object,
});

// Initialize filters from backend
onMounted(() => {
  if (props.filters) {
    search.value = props.filters.search || "";
    stock_status.value = props.filters.stock_status || null;
  }
  location_id.value = props.currentLocation?.id || null;
});

// Fetch items
const getItems = () => {
  router.reload({
    only: ["inventories"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
      stock_status: stock_status.value || undefined,
      location_id: location_id.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Filter options
const stockStatusOptions = computed(() => [
  { label: "In Stock", value: "in_stock" },
  { label: "Low Stock", value: "low_stock" },
  { label: "Out of Stock", value: "out_of_stock" },
]);

// Remove unused computed properties since we're using single filter approach

// Filter management
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
      label: "Stock Status",
      key: "stock_status",
      ref: stock_status,
      getLabel: toLabel(computed(() => stockStatusOptions.value)),
    },
  ],
});

// FilterDropdown configuration (single filter like Products/Index)
const filtersConfig = [
  {
    key: "stock_status",
    label: "Stock Status",
    type: "select",
    options: stockStatusOptions.value,
  },
];

// Group filters in one object
const tableFilters = { search, stock_status };

// Table management
const { pagination, handleTableChange } = useTable("inventories", tableFilters);

// Methods
const changeLocation = (newLocationId) => {
  location_id.value = newLocationId;
  getItems();
};

// Modal states
const receiveModalVisible = ref(false);
const transferModalVisible = ref(false);
const detailsModalVisible = ref(false);
const selectedProduct = ref(null);

const showReceiveModal = () => {
  receiveModalVisible.value = true;
};

const showTransferModal = () => {
  transferModalVisible.value = true;
};

const exportInventory = () => {
  // TODO: Implement export functionality
  console.log("Export inventory");
};

const showProductDetails = (inventory) => {
  selectedProduct.value = inventory;
  detailsModalVisible.value = true;
};

const handleTransferStock = (inventory) => {
  selectedProduct.value = inventory;
  transferModalVisible.value = true;
};
</script>

<template>
  <Head title="Inventory Products" />

  <AuthenticatedLayout>
    <ContentHeader title="Inventory Products">
      <template #actions>
        <a-select
          v-model:value="location_id"
          placeholder="Select Location"
          style="width: 200px; margin-right: 8px"
          @change="changeLocation"
        >
          <a-select-option
            v-for="location in locations"
            :key="location.id"
            :value="location.id"
          >
            {{ location.name }}
          </a-select-option>
        </a-select>

        <a-button @click="exportInventory" style="margin-right: 8px">
          <template #icon>
            <DownloadOutlined />
          </template>
          Export
        </a-button>

        <a-button
          type="primary"
          @click="showTransferModal"
          style="margin-right: 8px"
        >
          Transfer Stock
        </a-button>

        <a-button
          type="primary"
          @click="showReceiveModal"
          style="margin-right: 8px"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Receive Inventory
        </a-button>

        <RefreshButton @click="getItems" />
      </template>
    </ContentHeader>

    <ContentLayout title="Inventory Products">
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search products, SKU, or barcode..."
          class="min-w-[100px] max-w-[300px]"
        />
        <a-button
          @click="showReceiveModal"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Receive Inventory
        </a-button>
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
        <div v-if="currentLocation" class="mb-4">
          <a-alert
            :message="`Viewing inventory for: ${currentLocation.name}`"
            :description="currentLocation.address"
            type="info"
            show-icon
            closable
          />
        </div>
      </template>

      <template #table>
        <InventoryProductTable
          :inventories="inventories"
          :pagination="pagination"
          :loading="spinning"
          @handle-table-change="handleTableChange"
          @show-details="showProductDetails"
          @transfer-stock="handleTransferStock"
        />
      </template>
    </ContentLayout>

    <!-- Modals -->
    <ReceiveInventoryModal
      v-model:visible="receiveModalVisible"
      :locations="locations"
      :current-location="currentLocation"
      @success="getItems"
    />

    <TransferInventoryModal
      v-model:visible="transferModalVisible"
      :locations="locations"
      :current-location="currentLocation"
      :selected-product="selectedProduct"
      @success="getItems"
    />
    
    <ProductDetailsModal 
      v-model:visible="detailsModalVisible"
      :product="selectedProduct"
    />
  </AuthenticatedLayout>
</template>
