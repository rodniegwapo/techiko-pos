<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { DownloadOutlined, FilterOutlined } from "@ant-design/icons-vue";
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
import MovementsTable from "./components/MovementsTable.vue";

const page = usePage();
const { showModal } = useHelpers();
const { spinning } = useGlobalVariables();

const location_id = ref(null);
const product_id = ref(null);
const movement_type = ref(null);
const date_from = ref(null);
const date_to = ref(null);

// Props from backend
const props = defineProps({
  movements: Object,
  locations: Array,
  products: Array,
  movementTypes: Object,
  filters: Object,
});

// Initialize filters from backend
onMounted(() => {
  if (props.filters) {
    location_id.value = props.filters.location_id || null;
    product_id.value = props.filters.product_id || null;
    movement_type.value = props.filters.movement_type || null;
    date_from.value = props.filters.date_from || null;
    date_to.value = props.filters.date_to || null;
  }
});

// Fetch items
const getItems = () => {
  router.reload({
    only: ["movements"],
    preserveScroll: true,
    data: {
      location_id: location_id.value || undefined,
      product_id: product_id.value || undefined,
      movement_type: movement_type.value || undefined,
      date_from: date_from.value || undefined,
      date_to: date_to.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Filter options
const locationOptions = computed(() => 
  props.locations?.map(loc => ({ label: loc.name, value: loc.id })) || []
);

const productOptions = computed(() => 
  props.products?.map(product => ({ 
    label: `${product.name} (${product.SKU})`, 
    value: product.id 
  })) || []
);

const movementTypeOptions = computed(() => 
  Object.entries(props.movementTypes || {}).map(([key, label]) => ({ 
    label, 
    value: key 
  }))
);

// Filter management
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
      label: "Location",
      key: "location_id",
      ref: location_id,
      getLabel: toLabel(computed(() => locationOptions.value)),
    },
    {
      label: "Product",
      key: "product_id",
      ref: product_id,
      getLabel: toLabel(computed(() => productOptions.value)),
    },
    {
      label: "Movement Type",
      key: "movement_type",
      ref: movement_type,
      getLabel: toLabel(computed(() => movementTypeOptions.value)),
    },
  ],
});

// Table management
const { pagination, handleTableChange } = useTable(props.movements, getItems);

// Methods
const exportMovements = () => {
  // TODO: Implement export functionality
  console.log("Export movements");
};

const clearDateFilters = () => {
  date_from.value = null;
  date_to.value = null;
  getItems();
};
</script>

<template>
  <Head title="Inventory Movements" />

  <AuthenticatedLayout>
    <ContentHeader title="Inventory Movements">
      <template #actions>
        <a-button @click="exportMovements" style="margin-right: 8px">
          <template #icon>
            <DownloadOutlined />
          </template>
          Export
        </a-button>
        
        <RefreshButton @click="getItems" />
      </template>
    </ContentHeader>

    <ContentLayout title="Inventory Movements">
      <template #filters>
        <FilterDropdown
          v-model:value="location_id"
          :options="locationOptions"
          placeholder="All Locations"
          @change="getItems"
        />

        <FilterDropdown
          v-model:value="product_id"
          :options="productOptions"
          placeholder="All Products"
          @change="getItems"
          :show-search="true"
        />

        <FilterDropdown
          v-model:value="movement_type"
          :options="movementTypeOptions"
          placeholder="All Movement Types"
          @change="getItems"
        />

        <!-- Date Filters -->
        <div class="flex items-center space-x-2">
          <label class="text-sm font-medium text-gray-700">Date Range:</label>
          <a-date-picker
            v-model:value="date_from"
            placeholder="From Date"
            @change="getItems"
          />
          <span class="text-gray-500">to</span>
          <a-date-picker
            v-model:value="date_to"
            placeholder="To Date"
            @change="getItems"
          />
          <a-button 
            v-if="date_from || date_to" 
            type="link" 
            size="small"
            @click="clearDateFilters"
          >
            Clear Dates
          </a-button>
        </div>
      </template>

      <template #activeFilters>
        <!-- Active Filters -->
        <ActiveFilters
          v-if="activeFilters.length > 0 || date_from || date_to"
          :filters="[
            ...activeFilters,
            ...(date_from ? [{ key: 'date_from', label: 'From Date', value: date_from }] : []),
            ...(date_to ? [{ key: 'date_to', label: 'To Date', value: date_to }] : [])
          ]"
          @clear="(key) => {
            if (key === 'date_from') date_from.value = null;
            else if (key === 'date_to') date_to.value = null;
            else handleClearSelectedFilter(key);
            getItems();
          }"
          @clear-all="() => {
            location_id.value = null;
            product_id.value = null;
            movement_type.value = null;
            date_from.value = null;
            date_to.value = null;
            getItems();
          }"
        />

        <!-- Summary Stats -->
        <div v-if="movements?.data?.length > 0" class="mb-4">
          <a-card size="small">
            <div class="flex items-center justify-between text-sm">
              <div class="flex space-x-6">
                <span>
                  <strong>{{ movements.total || 0 }}</strong> total movements
                </span>
                <span>
                  <strong>{{ movements.data?.filter(m => m.quantity_change > 0).length || 0 }}</strong> stock increases
                </span>
                <span>
                  <strong>{{ movements.data?.filter(m => m.quantity_change < 0).length || 0 }}</strong> stock decreases
                </span>
              </div>
              <div>
                Showing {{ movements.from || 0 }} - {{ movements.to || 0 }} of {{ movements.total || 0 }}
              </div>
            </div>
          </a-card>
        </div>
      </template>

      <template #table>
        <!-- Movements Table -->
        <MovementsTable
          :movements="movements"
          :pagination="pagination"
          @handle-table-change="handleTableChange"
        />
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>
