<script setup>
import { ref, computed, onMounted } from "vue";
import { usePage, router, Head } from "@inertiajs/vue3";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { watchDebounced } from "@vueuse/core";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useHelpers } from "@/Composables/useHelpers";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import MandatoryDiscountTable from "./components/MandatoryDiscountTable.vue";
import AddModal from "./components/AddModal.vue";
import ViewDetailModal from "./components/ViewDetailModal.vue";

import { useTable } from "@/Composables/useTable";

const { showModal } = useHelpers();

// Page props
const page = usePage();

// Search input
const search = ref("");
const domain = ref(null);

// Check if this is a global view
const isGlobalView = computed(() => page.props.isGlobalView || false);

// Fetch items
const getItems = () => {
  router.reload({
    only: ["items"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
      domain: domain.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Table change handler
const tableFilters = { search, domain };
const { pagination, handleTableChange, spinning } = useTable(
  "items",
  tableFilters
);

// Domain options for filtering
const domainOptions = computed(() => {
  if (!page.props.domains) return [];
  return page.props.domains.map(domain => ({
    label: domain.name,
    value: domain.name_slug
  }));
});

const mandatoryDiscountDetail = ref({});
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Mandatory Discounts" />
    <ContentHeader class="mb-8" title="Mandatory Discounts" />
    <ContentLayout title="Mandatory Discounts">
      <!-- Filters -->
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search mandatory discount"
          class="min-w-[100px] max-w-[300px]"
        />
        <a-select
          v-if="isGlobalView"
          v-model:value="domain"
          placeholder="Filter by domain"
          allow-clear
          class="min-w-[150px] max-w-[200px]"
          @change="getItems"
        >
          <a-select-option
            v-for="option in domainOptions"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </a-select-option>
        </a-select>
        <a-button
          @click="showModal"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Create Mandatory Discount
        </a-button>
      </template>

      <!-- Table -->
      <template #table>
        <MandatoryDiscountTable
          :products="page.props.items.data"
          :pagination="pagination"
          :is-global-view="isGlobalView"
          @handle-table-change="handleTableChange"
          @selectedMandatoryDiscount="(data) => (mandatoryDiscountDetail = data)"
        />
      </template>
    </ContentLayout>

    <!-- add modal  -->
    <AddModal />

    <!-- view modal -->
    <ViewDetailModal :selectedMandatoryDiscount="mandatoryDiscountDetail" />

    
  </AuthenticatedLayout>
</template>
