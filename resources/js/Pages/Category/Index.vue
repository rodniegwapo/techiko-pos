<script setup>
import { Head } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable"; // 👈 import
import ContentHeader from "@/Components/ContentHeader.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import CategoryTable from "./components/CategoryTable.vue";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { ref } from "vue";
import { IconPlus } from "@tabler/icons-vue";
import AddModal from "./components/AddModal.vue";

const props = defineProps({
  items: Object,
});

// use composable
const { spinning, pagination, handleTableChange, getItems } = useTable(
  props,
  "categories.index"
);

const search = ref("");
const showCreateModal = () => {
  // Logic to show the create category modal
};

const handleRefresh = () => {
  getItems(pagination.value.pageSize, pagination.value.current, ["items"]);
};
</script>

<template>
  <AuthenticatedLayout>
    <add-modal :visible="true" />
    <Head title="Categories" />
    <ContentHeader class="mb-8" title="Categories" />
    <ContentLayout title="Categories">
      <template #filters>
        <refresh-button :loading="spinning" @click="handleRefresh" />
        <a-input-search
          v-model:value="search"
          placeholder="Input search text"
          style="width: 100%"
          class="min-w-[100px] max-w-[300px]"
        />
        <a-button
          @click="showCreateModal"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Create Category
        </a-button>
      </template>

      <template #table>
        <CategoryTable
          :categories="items.data"
          :pagination="pagination"
          @handle-table-change="handleTableChange"
        />
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>
