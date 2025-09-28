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
import StockAdjustmentsTable from "../components/StockAdjustmentsTable.vue";

const page = usePage();
const { showModal } = useHelpers();
const { spinning } = useGlobalVariables();

const status = ref(null);
const location_id = ref(null);
const date_from = ref(null);
const date_to = ref(null);

// Props from backend
const props = defineProps({
  adjustments: Object,
  locations: Array,
  statuses: Object,
  reasons: Object,
  filters: Object,
});

// Initialize filters from backend
onMounted(() => {
  if (props.filters) {
    status.value = props.filters.status || null;
    location_id.value = props.filters.location_id || null;
    date_from.value = props.filters.date_from || null;
    date_to.value = props.filters.date_to || null;
  }
});

// Fetch items
const getItems = () => {
  router.reload({
    only: ["adjustments"],
    preserveScroll: true,
    data: {
      status: status.value || undefined,
      location_id: location_id.value || undefined,
      date_from: date_from.value || undefined,
      date_to: date_to.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Filter options
const statusOptions = computed(() => 
  Object.entries(props.statuses || {}).map(([key, label]) => ({ 
    label, 
    value: key 
  }))
);

const locationOptions = computed(() => 
  props.locations?.map(loc => ({ label: loc.name, value: loc.id })) || []
);

// Filter management
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
      label: "Status",
      key: "status",
      ref: status,
      getLabel: toLabel(computed(() => statusOptions.value)),
    },
    {
      label: "Location",
      key: "location_id",
      ref: location_id,
      getLabel: toLabel(computed(() => locationOptions.value)),
    },
  ],
});

// Table management
const { pagination, handleTableChange } = useTable(props.adjustments, getItems);

// Methods
const createAdjustment = () => {
  router.visit(route('inventory.adjustments.create'));
};

const exportAdjustments = () => {
  // TODO: Implement export functionality
  console.log("Export adjustments");
};

const clearDateFilters = () => {
  date_from.value = null;
  date_to.value = null;
  getItems();
};
</script>

<template>
  <Head title="Stock Adjustments" />

  <AuthenticatedLayout>
    <ContentHeader title="Stock Adjustments">
      <template #actions>
        <a-button @click="exportAdjustments" style="margin-right: 8px">
          <template #icon>
            <DownloadOutlined />
          </template>
          Export
        </a-button>
        
        <a-button type="primary" @click="createAdjustment" style="margin-right: 8px">
          <template #icon>
            <PlusSquareOutlined />
          </template>
          New Adjustment
        </a-button>
        
        <RefreshButton @click="getItems" />
      </template>
    </ContentHeader>

    <ContentLayout title="Stock Adjustments">
      <template #filters>
        <FilterDropdown
          v-model:value="status"
          :options="statusOptions"
          placeholder="All Statuses"
          @change="getItems"
        />

        <FilterDropdown
          v-model:value="location_id"
          :options="locationOptions"
          placeholder="All Locations"
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
            status.value = null;
            location_id.value = null;
            date_from.value = null;
            date_to.value = null;
            getItems();
          }"
        />

        <!-- Summary Stats -->
        <div v-if="adjustments?.data?.length > 0" class="mb-4">
          <a-card size="small">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
              <div>
                <p class="text-2xl font-bold text-blue-600">
                  {{ adjustments.data?.filter(adj => adj.status === 'draft').length || 0 }}
                </p>
                <p class="text-sm text-gray-600">Draft</p>
              </div>
              <div>
                <p class="text-2xl font-bold text-yellow-600">
                  {{ adjustments.data?.filter(adj => adj.status === 'pending_approval').length || 0 }}
                </p>
                <p class="text-sm text-gray-600">Pending</p>
              </div>
              <div>
                <p class="text-2xl font-bold text-green-600">
                  {{ adjustments.data?.filter(adj => adj.status === 'approved').length || 0 }}
                </p>
                <p class="text-sm text-gray-600">Approved</p>
              </div>
              <div>
                <p class="text-2xl font-bold text-red-600">
                  {{ adjustments.data?.filter(adj => adj.status === 'rejected').length || 0 }}
                </p>
                <p class="text-sm text-gray-600">Rejected</p>
              </div>
            </div>
          </a-card>
        </div>
      </template>

      <template #table>
        <!-- Adjustments Table -->
        <StockAdjustmentsTable
          :adjustments="adjustments"
          :pagination="pagination"
          @handle-table-change="handleTableChange"
          @refresh="getItems"
        />
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>
