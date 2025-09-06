<script setup>
import { Head } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable";
import ContentHeader from "@/Components/ContentHeader.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import ProductTable from "./components/ProductTable.vue";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { ref } from "vue";
import { IconPlus } from "@tabler/icons-vue";
import AddModal from "./components/AddModal.vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { watchDebounced } from "@vueuse/core";
import { router } from "@inertiajs/vue3";

const { openModal, isEdit } = useGlobalVariables();

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

watchDebounced(search, getItems, { debounce: 300 });
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
          @click="(openModal = true), (isEdit = false)"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Create Product
        </a-button>
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


    