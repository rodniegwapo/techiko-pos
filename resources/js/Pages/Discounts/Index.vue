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
import DiscountTable from "./components/DiscountTable.vue";
import AddModal from "./components/AddModal.vue";

const { showModal } = useHelpers();

// Page props
const page = usePage();

// Table state (pagination/spinning)
const spinning = ref(false);
const pagination = ref(page.props.items.meta || {});

// Search input
const search = ref("");

const sold_type = ref(null);
const price = ref(null);
const category = ref(null);
const cost = ref(null);

// Fetch items
const getItems = () => {
  router.reload({
    only: ["items"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

// Watch search with debounce
watchDebounced(search, getItems, { debounce: 300 });

// Table change handler
const handleTableChange = (newPagination) => {
  pagination.value = newPagination;
  getItems();
};
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Discounts" />
    <ContentHeader class="mb-8" title="Discounts" />
    <ContentLayout title="Discounts">
      <!-- Filters -->
      <template #filters>
        <RefreshButton :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Search discount"
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
          Create Discount
        </a-button>
      </template>

      <!-- Table -->
      <template #table>
        <DiscountTable
          :products="page.props.items.data"
          :pagination="pagination"
          @handle-table-change="handleTableChange"
        />
      </template>
    </ContentLayout>

    <AddModal />
  </AuthenticatedLayout>
</template>
