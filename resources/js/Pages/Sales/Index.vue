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

import {
  CloseOutlined,
  PlusSquareOutlined,
  MinusSquareOutlined,
} from "@ant-design/icons-vue";

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
const orders = ["1", "1", "1", "1", "1", "1", "1", "1", "1", "1"];
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
          :always-show="true"
        />
      </template>

      <template #table>
        <ProductTable :products="products" />
      </template>
      <template #right-side-content>
        <div class="space-y-2">
          <div class="font-semibold text-lg">Current Order</div>
          <a-input value="Walk-in Customer" disabled />
        </div>
        <div
          class="flex flex-col gap-2 mt-2 h-[calc(100vh-350px)] overflow-scroll overflow-x-hidden"
        >
          <div
            v-for="(order, index) in orders"
            :key="index"
            class="flex justify-between items-center border px-4 rounded-lg bg-white hover:shadow cursor-pointer"
          >
            <div>
              <div class="text-sm font-semibold">Banana Bread</div>

              <div
                class="text-xs flex items-center bg-transparent text-gray-800 border-none shadow-none gap-1 mt-1"
              >
                <PlusSquareOutlined />
                <span>2</span>
                <MinusSquareOutlined />
              </div>
            </div>
            <div class="text-right">
              <div class="text-red-600 mt-1">
                <CloseOutlined />
              </div>
              <div class="text-xs text-green-700 mt-1">₱ 500</div>
            </div>
          </div>
        </div>
        <hr class="-mx-6 border-t-[3px] pt-2 mt-2" />
        <div class="font-bold text-lg">
          Total: <span class="text-green-700">₱ 500</span>
        </div>
        <div class="mt-2">
          <div>Payment Method</div>
          <a-input value="Pay in Cash " disabled></a-input>
        </div>
        <div>
          <a-button
            class="w-full mt-2 bg-green-700 border-green-700 hover:bg-green-600"
            type="primary"
            >Proceed Payment</a-button
          >
        </div>
      </template>
    </ContentLayoutV2>
  </AuthenticatedLayout>
</template>