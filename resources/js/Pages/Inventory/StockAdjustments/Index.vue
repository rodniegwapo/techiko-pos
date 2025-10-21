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
import AdjustmentDetailsModal from "../components/AdjustmentDetailsModal.vue";

const page = usePage();
const { showModal } = useHelpers();
const { spinning } = useGlobalVariables();

const search = ref("");
const status = ref(null);
const domain = ref(null);

// Props from backend
const props = defineProps({
  adjustments: Object,
  locations: Array,
  statuses: Object,
  reasons: Object,
  domains: Array,
  filters: Object,
});

// Initialize filters from backend
onMounted(() => {
  if (props.filters) {
    search.value = props.filters.search || "";
    status.value = props.filters.status || null;
    domain.value = props.filters.domain || null;
  }
});

// Fetch items
const getItems = () => {
  router.reload({
    only: ["adjustments"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
      status: status.value || undefined,
      domain: domain.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Filter options
const statusOptions = computed(() => 
  Object.entries(props.statuses || {}).map(([key, label]) => ({ 
    label, 
    value: key 
  }))
);

const domainOptions = computed(() => 
  (props.domains || []).map(domain => ({ 
    label: domain.name, 
    value: domain.name_slug 
  }))
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
    ...(page.props.isGlobalView ? [{
      label: "Domain",
      key: "domain",
      ref: domain,
      getLabel: toLabel(computed(() => domainOptions.value)),
    }] : []),
  ],
});

// FilterDropdown configuration (single filter like Products/Index)
const filtersConfig = [
  {
    key: "status",
    label: "Status",
    type: "select",
    options: statusOptions.value,
  },
  ...(page.props.isGlobalView ? [{
    key: "domain",
    label: "Domain",
    type: "select",
    options: domainOptions.value,
  }] : []),
];

// Group filters in one object
const tableFilters = { search, status, domain };

// Table management
const { pagination, handleTableChange } = useTable("adjustments", tableFilters);

// Methods
const createAdjustment = () => {
  router.visit(route('inventory.adjustments.create'));
};

const exportAdjustments = () => {
  // TODO: Implement export functionality
  console.log("Export adjustments");
};

// Modal states for adjustment details
const detailsModalVisible = ref(false);
const selectedAdjustment = ref(null);

const showAdjustmentDetails = (adjustment) => {
  selectedAdjustment.value = adjustment;
  detailsModalVisible.value = true;
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
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search adjustments, reasons, or notes..."
          class="min-w-[100px] max-w-[300px]"
        />
        <a-button class="bg-white border flex items-center border-green-500 text-green-500" type="primary" @click="createAdjustment">
          <template #icon>
            <PlusSquareOutlined />
          </template>
          New Adjustment
        </a-button>
        <a-button @click="exportAdjustments">
          <template #icon>
            <DownloadOutlined />
          </template>
          Export
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
      </template>

      <template #table>
        <StockAdjustmentsTable
          :adjustments="adjustments"
          :pagination="pagination"
          :loading="spinning"
          :is-global-view="page.props.isGlobalView"
          @handle-table-change="handleTableChange"
          @show-details="showAdjustmentDetails"
          @refresh="getItems"
        />
      </template>
    </ContentLayout>

    <!-- Adjustment Details Modal -->
    <AdjustmentDetailsModal 
      v-model:visible="detailsModalVisible"
      :adjustment="selectedAdjustment"
      @refresh="getItems"
    />
  </AuthenticatedLayout>
</template>
