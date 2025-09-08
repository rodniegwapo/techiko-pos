<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { useFilters, toLabel } from "@/Composables/useFilters";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import ProductTable from "./components/ProductTable.vue";
import AddModal from "./components/AddModal.vue";

// Page props
const page = usePage();

// Table state (pagination/spinning)
const spinning = ref(false);
const pagination = ref(page.props.items.meta || {});

// Search input
const search = ref("");

const sold_type = ref(null);
const price = ref(null);
const category = ref(null)
const cost = ref(null)

// Fetch items
const getItems = () => {
  router.reload({
    only: ["items"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
      sold_type: sold_type.value || undefined,
      price: price.value || undefined,
      category: category.value || undefined, 
      cost: cost.value || undefined, 
      page: pagination.value.current_page || 1,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
      label: "Category",
      key: "category",
      ref: category,
      getLabel: toLabel(
        computed(() =>
          page.props.categories.map((item) => ({
            label: item.name,
            value: item.name,
          }))
        )
      ),
    },
    {
      label: "Sold type",
      key: "sold_type",
      ref: sold_type,
      getLabel: toLabel(
        computed(() =>
          page.props.sold_by_types.map((item) => ({
            label: item.name,
            value: item.name,
          }))
        )
      ),
    },
    { key: "cost", ref: cost, label: "Cost" },
    { key: "price", ref: price, label: "Price" },
  ],
});

// FilterDropdown configuration
const filtersConfig = [
  {
    key: "category",
    label: "Category",
    type: "select",
    options: page.props?.categories.map((item) => ({
      label: item.name,
      value: item.name,
    })),
  },
  {
    key: "sold_type",
    label: "Sold Type",
    type: "select",
    options: page.props.sold_by_types.map((item) => ({
      label: item.name,
      value: item.name,
    })),
  },
  { key: "cost", label: "Cost", type: "number" },
  { key: "price", label: "Price", type: "number" },
];

// Table change handler
const handleTableChange = (newPagination) => {
  pagination.value = newPagination;
  getItems();
};
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Products" />
    <ContentHeader class="mb-8" title="Products" />
    <ContentLayout title="Products">
      <!-- Filters -->
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search products"
          class="min-w-[100px] max-w-[300px]"
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

      <!-- Table -->
      <template #table>
        <ProductTable
          :products="page.props.items.data"
          :pagination="pagination"
          @handle-table-change="handleTableChange"
        />
      </template>
    </ContentLayout>

    <AddModal />
  </AuthenticatedLayout>
</template>
