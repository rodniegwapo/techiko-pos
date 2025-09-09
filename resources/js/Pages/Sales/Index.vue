<script setup >
import { ref, computed, onMounted } from "vue";
import axios from "axios";

import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayoutV2 from "@/Components/ContentLayoutV2.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import ProductTable from "./components/ProductTable.vue";

import { PlusSquareOutlined } from "@ant-design/icons-vue";

import { usePage, router, Head } from "@inertiajs/vue3";
import { useFilters, toLabel } from "@/Composables/useFilters";

const page = usePage();

const search = ref("");
const category = ref();
const spinning = ref(false);
const getItems = () => {};

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
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
];

const products = ref([]);
const loading = ref(false);
onMounted(async () => {
  getProducts();
});

const getProducts = async () => {
  loading.value = true;
  const items = await axios.get(route("sales.products"));
  products.value = items.data.data;
  loading.value = false;
};
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Sales" />
    <ContentHeader class="mb-8" title="Sales" />
    <ContentLayoutV2 title="Create Transaction">
      <template #filters>
        <a-input-search
          v-model:value="search"
          placeholder="Search Product"
          class="min-w-[100px] max-w-[300px]"
        />
        <RefreshButton :loading="spinning" @click="getItems" />
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
        <ProductTable :products="products" />
      </template>
      <div>dsdsss</div>
    </ContentLayoutV2>
  </AuthenticatedLayout>
</template>