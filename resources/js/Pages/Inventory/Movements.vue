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
import MovementDetailsModal from "./components/MovementDetailsModal.vue";

const page = usePage();
const { showModal } = useHelpers();
const { spinning } = useGlobalVariables();

const search = ref("");
const movement_type = ref(null);

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
    search.value = props.filters.search || "";
    movement_type.value = props.filters.movement_type || null;
  }
});

// Fetch items
const getItems = () => {
  router.reload({
    only: ["movements"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
      movement_type: movement_type.value || undefined,
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
];

// Group filters in one object
const tableFilters = { search, movement_type };

// Table management
const { pagination, handleTableChange } = useTable("movements", tableFilters);

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
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search movements, products, or references..."
          class="min-w-[100px] max-w-[300px]"
        />
        <a-button @click="exportMovements">
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
        <MovementsTable
          :movements="movements"
          :pagination="pagination"
          :loading="spinning"
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
