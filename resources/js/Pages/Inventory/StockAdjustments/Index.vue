<script setup>
import { ref, computed } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { PlusSquareOutlined, DownloadOutlined } from "@ant-design/icons-vue";
import { watchDebounced } from "@vueuse/core";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useTable } from "@/Composables/useTable";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import StockAdjustmentsTable from "../components/StockAdjustmentsTable.vue";
import AdjustmentDetailsModal from "../components/AdjustmentDetailsModal.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
const { showModal } = useHelpers();
const { spinning } = useGlobalVariables();
const { hasPermission } = usePermissionsV2();

// Creating needs its own permission, so users who only review adjustments aren't offered it.
const canCreate = computed(() => hasPermission("inventory.adjustments.create"));

// Props from backend
const props = defineProps({
  adjustments: Object,
  locations: Array,
  statuses: Object,
  reasons: Object,
  domains: Array,
  filters: Object,
});

// Seeded from the URL's own filters. Assigning these after mount instead would look like the user
// had just typed, and the reload that followed would cancel whatever request was in flight.
const search = ref(props.filters?.search ?? "");
const status = ref(props.filters?.status ?? null);
const domain = ref(props.filters?.domain ?? null);

// Fetch items
const getItems = () => {
  router.reload({
    only: ["adjustments"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
      status: status.value || undefined,
      domain: domain.value || undefined,
      // A new search or filter starts over; keeping the old page hides the matches.
      page: 1,
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
    <ContentHeader class="mb-4 md:mb-8" title="Stock Adjustments">
      <template #actions> </template>
    </ContentHeader>

    <ContentLayout
      title="Stock Adjustments"
      filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
    >
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search adjustments, reasons, or notes..."
          class="w-full min-w-0 md:max-w-[300px]"
        />
        <a-button
          v-if="canCreate"
          class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
          type="primary"
          @click="createAdjustment"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          New Adjustment
        </a-button>
        <a-button class="w-full md:w-auto" @click="exportAdjustments">
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

      <template #activeStore>
        <LocationInfoAlert />
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
