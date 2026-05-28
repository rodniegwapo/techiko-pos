<script setup>
import { Head, usePage } from "@inertiajs/vue3";
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
import { watchDebounced } from "@vueuse/core";
import { router } from "@inertiajs/vue3";
import { useHelpers } from "@/Composables/useHelpers";
import LocationInfoAlert from "@/Components/LocationInfoAlert.vue";

const page = usePage();
const { openModal, isEdit } = useGlobalVariables();
const { showModal } = useHelpers();

const props = defineProps({
  items: Object,
});

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

// use composable
// group all filters in one object
const tableFilters = { search };
const { pagination, handleTableChange ,spinning} = useTable("items", tableFilters);
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Categories" />
    <ContentHeader class="mb-4 md:mb-8" title="Categories" />
    <ContentLayout
      title="Categories"
      filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
    >
      <template #filters>
        <refresh-button :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Input search text"
          class="w-full min-w-0 md:max-w-[300px]"
        />
        <a-button
          @click="showModal"
          type="primary"
          class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Create Category
        </a-button>
      </template>

      <template #activeStore>
        <LocationInfoAlert />
      </template>

      <template #table>
        <CategoryTable
          :categories="items.data"
          :pagination="pagination"
          :is-global-view="page.props.isGlobalView"
          @handle-table-change="handleTableChange"
        />
      </template>
    </ContentLayout>
  </AuthenticatedLayout>

  <!-- add modal -->
  <add-modal />
</template>


    