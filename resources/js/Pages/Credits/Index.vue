<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconCreditCard, IconAlertCircle } from "@tabler/icons-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useCredit } from "@/Composables/useCredit";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import CreditCustomerTable from "./components/CreditCustomerTable.vue";
import OverdueAlerts from "./components/OverdueAlerts.vue";
import CreditLimitModal from "./components/CreditLimitModal.vue";

const page = usePage();
const { openModal, isEdit, spinning } = useGlobalVariables();
const { showModal } = useHelpers();
const { getOverdueAccounts, loading: creditLoading } = useCredit();

const props = defineProps({
  customers: Object,
  domain: Object,
  filters: Object,
});

// Filter state
const search = ref(props.filters?.search || "");
const status = ref(props.filters?.status || null);

// Modal state
const selectedCustomer = ref(null);
const showCreditLimitModal = ref(false);
const overdueAccounts = ref([]);

// Fetch customers
const getItems = () => {
  router.reload({
    only: ["customers"],
    preserveScroll: true,
    data: {
      page: 1,
      search: search.value || undefined,
      status: status.value || undefined,
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
      label: "Status",
      key: "status",
      ref: status,
      getLabel: toLabel(
        computed(() => [
          { label: "All", value: null },
          { label: "Overdue", value: "overdue" },
          { label: "At Limit", value: "at_limit" },
          { label: "Good Standing", value: "good_standing" },
          { label: "Credit Enabled", value: "enabled" },
          { label: "Credit Disabled", value: "disabled" },
        ])
      ),
    },
  ],
});

// FilterDropdown configuration
const filtersConfig = [
  {
    key: "status",
    label: "Status",
    type: "select",
    options: [
      { label: "All", value: null },
      { label: "Overdue", value: "overdue" },
      { label: "At Limit", value: "at_limit" },
      { label: "Good Standing", value: "good_standing" },
      { label: "Credit Enabled", value: "enabled" },
      { label: "Credit Disabled", value: "disabled" },
    ],
  },
];

// Table composable
const tableFilters = { search, status };
const { pagination, handleTableChange } = useTable("customers", tableFilters);

// Load overdue accounts
const loadOverdueAccounts = async () => {
  overdueAccounts.value = await getOverdueAccounts();
};

onMounted(() => {
  loadOverdueAccounts();
});

// Methods
const handleViewCustomer = (customer) => {
  router.visit(
    page.props.ziggy?.urls?.["domains.credits.show"]?.replace(
      "{domain}",
      props.domain.name_slug
    )?.replace("{customer}", customer.id) ||
      `/domains/${props.domain.name_slug}/credits/customers/${customer.id}`
  );
};

const handleEditCreditLimit = (customer) => {
  selectedCustomer.value = customer;
  showCreditLimitModal.value = true;
};

const handleModalClose = () => {
  showCreditLimitModal.value = false;
  selectedCustomer.value = null;
};

const handleCreditSettingsSaved = () => {
  handleModalClose();
  getItems();
  loadOverdueAccounts();
};
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Credit Management" />
    <ContentHeader class="mb-8" title="Credit Management" />

    <ContentLayout title="Customer Credit Accounts">
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search customers by name, email, or phone..."
          class="min-w-[100px] max-w-[400px]"
        />
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

      <!-- Overdue Alerts -->
      <template #alerts>
        <OverdueAlerts
          :overdue-accounts="overdueAccounts"
          :loading="creditLoading"
          @refresh="loadOverdueAccounts"
        />
      </template>

      <template #table>
        <CreditCustomerTable
          :customers="customers?.data || []"
          :loading="spinning"
          :pagination="pagination"
          @change="handleTableChange"
          @view="handleViewCustomer"
          @edit-limit="handleEditCreditLimit"
        />
      </template>
    </ContentLayout>

    <!-- Credit Limit Modal -->
    <CreditLimitModal
      :visible="showCreditLimitModal"
      :customer="selectedCustomer"
      @close="handleModalClose"
      @saved="handleCreditSettingsSaved"
    />
  </AuthenticatedLayout>
</template>
