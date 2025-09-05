<script setup>
import { Head } from "@inertiajs/vue3";
import { useTable } from "@/Composables/useTable"; 
import ContentHeader from "@/Components/ContentHeader.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import CategoryTable from "./components/CategoryTable.vue";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { ref } from "vue";
import { IconPlus } from "@tabler/icons-vue";
import AddModal from "./components/AddModal.vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const { openModal, isEdit } = useGlobalVariables();

const props = defineProps({
  items: Object,
});

// use composable
const { spinning, pagination, handleTableChange, getItems } = useTable(
  props,
  "categories.index"
);

const search = ref("");

const handleRefresh = () => {
  getItems(pagination.value.pageSize, pagination.value.current, ["items"]);
};
</script>

<template>
  <AuthenticatedLayout>
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
          @click="(openModal = true), (isEdit = false)"
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

  <!-- add modal -->
  <add-modal />
</template>


    