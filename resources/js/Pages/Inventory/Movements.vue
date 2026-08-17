<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { DownloadOutlined } from "@ant-design/icons-vue";
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
import MovementDetailsModal from "./components/MovementDetailsModal.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
const { showModal } = useHelpers();
const { spinning } = useGlobalVariables();

const search = ref("");
const movement_type = ref(null);
const domain = ref(null);

// Props from backend
const props = defineProps({
  movements: Object,
  locations: Array,
  products: Array,
  movementTypes: Object,
  domains: Array,
  filters: Object,
});

// Initialize filters from backend
onMounted(() => {
  if (props.filters) {
    search.value = props.filters.search || "";
    movement_type.value = props.filters.movement_type || null;
    domain.value = props.filters.domain || null;
  }
});

const locationIdQuery = () => {
  if (typeof window === "undefined") {
    return {};
  }
  const url = new URL(page.url, window.location.origin);
  const id = url.searchParams.get("location_id");
  return id ? { location_id: id } : {};
};

// Fetch items
const getItems = () => {
  router.reload({
    only: ["movements", "currentLocation"],
    preserveScroll: true,
    data: {
      ...locationIdQuery(),
      search: search.value || undefined,
      movement_type: movement_type.value || undefined,
      domain: domain.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Filter options
const movementTypeOptions = computed(() => 
  Object.entries(props.movementTypes || {}).map(([key, label]) => ({ 
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
      label: "Movement Type",
      key: "movement_type",
      ref: movement_type,
      getLabel: toLabel(computed(() => movementTypeOptions.value)),
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
    key: "movement_type",
    label: "Movement Type",
    type: "select",
    options: movementTypeOptions.value,
  },
  ...(page.props.isGlobalView ? [{
    key: "domain",
    label: "Domain",
    type: "select",
    options: domainOptions.value,
  }] : []),
];

// Group filters in one object
const tableFilters = { search, movement_type, domain };

// Table management (keep location_id when filtering/paginating in domain context)
const { pagination, handleTableChange } = useTable("movements", tableFilters, {
  preserveQueryKeys: ["location_id"],
});

// Methods
const exportMovements = () => {
  // TODO: Implement export functionality
  console.log("Export movements");
};

// Modal states for movement details
const detailsModalVisible = ref(false);
const selectedMovement = ref(null);

const showMovementDetails = (movement) => {
  selectedMovement.value = movement;
  detailsModalVisible.value = true;
};
</script>

<template>
  <Head title="Inventory Movements" />

  <AuthenticatedLayout>
    <ContentHeader class="mb-4 md:mb-8" title="Inventory Movements">
      <template #actions> </template>
    </ContentHeader>

    <ContentLayout
      title="Inventory Movements"
      filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
    >
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search movements, products, or references..."
          class="w-full min-w-0 md:max-w-[300px]"
        />
        <a-button class="w-full md:w-auto" @click="exportMovements">
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

      <a-alert
        v-if="!page.props.isGlobalView && !page.props.currentLocation"
        type="warning"
        show-icon
        message="Select a store"
        description="Choose a location or open this page with ?location_id= in the URL to view movements for this organization."
        class="mb-4"
      />

      <template #table>
        <MovementsTable
          :movements="movements"
          :pagination="pagination"
          :loading="spinning"
          :is-global-view="page.props.isGlobalView"
          @handle-table-change="handleTableChange"
          @show-details="showMovementDetails"
        />
      </template>
    </ContentLayout>

    <!-- Movement Details Modal -->
    <MovementDetailsModal 
      v-model:visible="detailsModalVisible"
      :movement="selectedMovement"
    />
  </AuthenticatedLayout>
</template>
