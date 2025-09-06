<script setup>
import { Head } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import ContentHeader from "@/Components/ContentHeader.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import ProductTable from "./components/ProductTable.vue";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import { ref, computed, reactive, toRef } from "vue";
import { IconPlus } from "@tabler/icons-vue";
import AddModal from "./components/AddModal.vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { watchDebounced } from "@vueuse/core";
import { router } from "@inertiajs/vue3";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { usePage } from "@inertiajs/vue3";

const { openModal, isEdit, formData, formFilters } = useGlobalVariables();
const { showModal } = useHelpers();
const page = usePage();

const props = defineProps({
  items: Object,
});

// use composable
const { spinning, pagination, handleTableChange } = useTable(props);

const search = ref("");

const getItems = () => {
  router.reload({
    only: ["items"],
    preserveScroll: true,
    data: {
      page: 1,
      search: search.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

const soldTypeOptions = computed(() => {
  return page?.props.sold_by_types.map((item) => {
    return { label: item.name, value: item.name };
  });
});

watchDebounced(search, getItems, { debounce: 300 });

const { filters, activeFilters, handleClearSelectedFilter, handleClearFilter } =
  useFilters({
    getItems,
    configs: [
      {
        key: "sold_type",
        ref: toRef(formFilters, "sold_type"),
        getLabel: toLabel(soldTypeOptions),
      },
    ],
  });

const filtersConfig = [
  {
    key: "sold_type",
    label: "Sold Type",
    type: "select",
    options: page?.props.sold_by_types.map((item) => item.name),
  },
];
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Products" />
    <ContentHeader class="mb-8" title="Products" />
    <ContentLayout title="Products">
      <template #filters>
        <refresh-button :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Input search text"
          style="width: 100%"
          class="min-w-[100px] max-w-[300px]"
        />
        <a-button
          @click="showModal"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Create Product
        </a-button>
        <FilterDropdown v-model="filters" :filters="filtersConfig" />
      </template>

      <template #activeFilters>
        <!-- Active Filters -->
        <ActiveFilters
          :filters="activeFilters"
          @remove-filter="handleClearSelectedFilter"
        />
      </template>
      <template #table>
        <ProductTable
          :products="items.data"
          :pagination="pagination"
          @handle-table-change="handleTableChange"
        />
      </template>
    </ContentLayout>
  </AuthenticatedLayout>

  <!-- add modal -->
  <add-modal />
</template>


    