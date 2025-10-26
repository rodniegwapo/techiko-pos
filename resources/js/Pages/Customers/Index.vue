<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconPlus, IconUsers } from "@tabler/icons-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import CustomerTable from "./components/CustomerTable.vue";
import AddCustomerModal from "./components/AddCustomerModal.vue";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";
import CustomerDetailsModal from "./components/CustomerDetailsModal.vue";

const page = usePage();
const { openModal, isEdit, spinning } = useGlobalVariables();
const { showModal } = useHelpers();

const props = defineProps({
  items: Object,
});

// Filter state
const search = ref("");
const loyalty_status = ref(null);
const tier = ref(null);
const date_range = ref(null);

// Modal state
const selectedCustomer = ref(null);
const showDetailsModal = ref(false);
const showAddModal = ref(false);

// Fetch customers
const getItems = () => {
  router.reload({
    only: ["items"],
    preserveScroll: true,
    data: {
      page: 1,
      search: search.value || undefined,
      loyalty_status: loyalty_status.value || undefined,
      tier: tier.value || undefined,
      date_range: date_range.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

watchDebounced(search, getItems, { debounce: 300 });

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
      label: "Loyalty Status",
      key: "loyalty_status",
      ref: loyalty_status,
      getLabel: toLabel(
        computed(() => [
          { label: "Enrolled", value: "enrolled" },
          { label: "Not Enrolled", value: "not_enrolled" },
        ])
      ),
    },
    {
      label: "Tier",
      key: "tier",
      ref: tier,
      getLabel: toLabel(
        computed(() => [
          { label: "Bronze", value: "bronze" },
          { label: "Silver", value: "silver" },
          { label: "Gold", value: "gold" },
          { label: "Platinum", value: "platinum" },
        ])
      ),
    },
    {
      label: "Registration Period",
      key: "date_range",
      ref: date_range,
      getLabel: toLabel(
        computed(() => [
          { label: "Last 7 days", value: "7_days" },
          { label: "Last 30 days", value: "30_days" },
          { label: "Last 3 months", value: "3_months" },
          { label: "Last year", value: "1_year" },
        ])
      ),
    },
  ],
});

// FilterDropdown configuration
const filtersConfig = [
  {
    key: "loyalty_status",
    label: "Loyalty Status",
    type: "select",
    options: [
      { label: "Enrolled", value: "enrolled" },
      { label: "Not Enrolled", value: "not_enrolled" },
    ],
  },
  {
    key: "tier",
    label: "Tier",
    type: "select",
    options: [
      { label: "Bronze", value: "bronze" },
      { label: "Silver", value: "silver" },
      { label: "Gold", value: "gold" },
      { label: "Platinum", value: "platinum" },
    ],
  },
  {
    key: "date_range",
    label: "Registration Period",
    type: "select",
    options: [
      { label: "Last 7 days", value: "7_days" },
      { label: "Last 30 days", value: "30_days" },
      { label: "Last 3 months", value: "3_months" },
      { label: "Last year", value: "1_year" },
    ],
  },
];

// Table composable
const tableFilters = { search, loyalty_status, tier, date_range };
const { pagination, handleTableChange } = useTable("items", tableFilters);

// Methods
const handleAddCustomer = () => {
  isEdit.value = false;
  showAddModal.value = true;
};

const handleEditCustomer = (customer) => {
  selectedCustomer.value = customer;
  isEdit.value = true;
  showAddModal.value = true;
};

const handleViewCustomer = (customer) => {
  selectedCustomer.value = customer;
  showDetailsModal.value = true;
};

const handleModalClose = () => {
  showAddModal.value = false;
  showDetailsModal.value = false;
  selectedCustomer.value = null;
  isEdit.value = false;
};

const handleCustomerSaved = () => {
  handleModalClose();
  getItems();
};

// Debug - log the items data
console.log("Items prop:", props.items);
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Customers" />
    <ContentHeader class="mb-8" title="Customer Management" />
    
    <ContentLayout title="Customer Management">
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search customers by name, email, or phone..."
          class="min-w-[100px] max-w-[400px]"
        />
        <a-button
          @click="handleAddCustomer"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
        >
          <template #icon>
            <IconPlus />
          </template>
          Add Customer
        </a-button>
        <FilterDropdown v-model="filters" :filters="filtersConfig" />
      </template>

      <!-- Active Filters -->
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
        <CustomerTable
          :customers="items?.data || []"
          :loading="spinning"
          :pagination="pagination"
          :is-global-view="page.props.isGlobalView"
          @change="handleTableChange"
          @edit="handleEditCustomer"
          @view="handleViewCustomer"
        />
      </template>
    </ContentLayout>

    <!-- Add/Edit Customer Modal -->
    <AddCustomerModal
      :visible="showAddModal"
      :customer="selectedCustomer"
      :is-edit="isEdit"
      @close="handleModalClose"
      @saved="handleCustomerSaved"
    />

    <!-- Customer Details Modal -->
    <CustomerDetailsModal
      :visible="showDetailsModal"
      :customer="selectedCustomer"
      @close="handleModalClose"
      @edit="handleEditCustomer"
    />
  </AuthenticatedLayout>
</template>
