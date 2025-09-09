<script setup >
import { ref, computed, onMounted } from "vue";
import axios from "axios";

import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayoutV2 from "@/Components/ContentLayoutV2.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
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
        <div
          class="grid grid-cols-[repeat(auto-fit,minmax(250px,1fr))] gap-4 mt-8 overflow-scroll"
        >
          <div
            v-for="(product, index) in products"
            :key="index"
            class="flex justify-between items-start border px-4 py-3 rounded-lg shadow bg-white"
          >
            <div>
              <div class="text-sm font-medium">{{ product.name }}</div>
              <div
                class="text-[10px] text-white bg-green-600 w-fit px-2 py-[1px] rounded-full mt-1"
              >
                Bakery
              </div>
            </div>
            <div class="text-right">
              <div class="text-md text-gray-700 font-semibold">
                ₱ {{ product.price }}
              </div>
              <a-button
                type="primary"
                class="text-xs flex items-center p-0 mt-1 bg-transparent text-green-600 border-none shadow-none"
                size="small"
              >
                <PlusSquareOutlined class="mr-1" /> Add to Cart
              </a-button>
            </div>
          </div>
        </div>
      </template>
      <div>dsdsss</div>
    </ContentLayoutV2>
  </AuthenticatedLayout>
</template>