<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconTrash, IconEdit, IconWorld } from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const emit = defineEmits(["handleTableChange"]);
const { confirmDelete } = useHelpers();
const { formData, openModal, isEdit, spinning } = useGlobalVariables();
const { getRoute } = useDomainRoutes();
const page = usePage();

const isMdUp = useMediaQuery("(min-width: 768px)");

const props = defineProps({
  categories: { type: Object, required: true },
  pagination: { type: Object, required: false, default: () => ({}) },
  isGlobalView: { type: Boolean, default: false },
});

const showSuperUserDomain = computed(
  () =>
    page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

const columns = computed(() => {
  const baseColumns = [
    { title: "Category", dataIndex: "name", key: "name", align: "left" },
    {
      title: "Description",
      dataIndex: "description",
      key: "description",
      align: "left",
    },
  ];

  if (showSuperUserDomain.value) {
    baseColumns.splice(1, 0, {
      title: "Domain",
      dataIndex: "domain",
      key: "domain",
      align: "left",
    });
  }

  baseColumns.push({
    title: "Action",
    key: "action",
    align: "center",
    width: "1%",
  });

  return baseColumns;
});

const handleTableChange = (event) => {
  emit("handleTableChange", event);
};

const handleDeleteCategory = (record) => {
  confirmDelete(
    "categories.destroy",
    { category: record.id },
    "Do you want to delete this item ?",
  );
};

const handleClickEdit = (record) => {
  openModal.value = true;
  formData.value = record;
  isEdit.value = true;
};

function onMobilePaginationChange(pageNum) {
  emit("handleTableChange", {
    current: pageNum,
    pageSize: props.pagination?.pageSize ?? 10,
  });
}
</script>

<template>
  <a-table
    v-if="isMdUp"
    class="ant-table-striped"
    :columns="columns"
    :data-source="categories"
    :row-class-name="
      (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
    "
    @change="handleTableChange"
    :pagination="pagination"
    :loading="spinning"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key == 'domain'">
        <div class="flex items-center">
          <IconWorld class="mr-1" size="16" />
          <span class="text-sm font-medium">{{
            record.domain || "N/A"
          }}</span>
        </div>
      </template>

      <template v-if="column.key == 'action'">
        <div class="flex items-center gap-2">
          <icon-tooltip-button
            hover="group-hover:bg-blue-500"
            name="Edit Category"
            @click="handleClickEdit(record)"
          >
            <IconEdit size="20" class="mx-auto" />
          </icon-tooltip-button>

          <icon-tooltip-button
            hover="group-hover:bg-red-500"
            name="Delete Category"
            @click="handleDeleteCategory(record)"
          >
            <IconTrash size="20" class="mx-auto" />
          </icon-tooltip-button>
        </div>
      </template>
    </template>
  </a-table>

  <div v-else class="px-2 py-2 md:px-0">
    <a-spin :spinning="spinning">
      <div
        v-if="!categories?.length"
        class="py-12 text-center text-sm text-gray-500"
      >
        No categories found.
      </div>
      <div v-else class="flex flex-col gap-3">
        <div
          v-for="record in categories"
          :key="record.id"
          class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
        >
          <div class="px-4 py-3">
            <div class="truncate text-base font-semibold text-gray-900">
              {{ record.name }}
            </div>
            <div class="mt-1 text-sm text-gray-600">
              {{ record.description || "No description" }}
            </div>
          </div>

          <div
            v-if="showSuperUserDomain"
            class="mx-4 mb-3 rounded-lg bg-gray-50 p-3"
          >
            <div
              class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
            >
              <span class="text-gray-500">Domain</span>
              <span
                class="flex min-w-0 items-center justify-end gap-1 truncate font-medium text-gray-900"
              >
                <IconWorld size="16" class="shrink-0" />
                {{ record.domain || "N/A" }}
              </span>
            </div>
          </div>

          <div class="border-t border-gray-100 px-4 py-3">
            <div class="grid grid-cols-2 gap-2">
              <a-button
                class="flex items-center justify-center gap-2"
                @click="handleClickEdit(record)"
              >
                <template #icon>
                  <IconEdit size="18" />
                </template>
                Edit
              </a-button>
              <a-button
                class="flex items-center justify-center gap-2"
                danger
                @click="handleDeleteCategory(record)"
              >
                <template #icon>
                  <IconTrash size="18" />
                </template>
                Delete
              </a-button>
            </div>
          </div>
        </div>
      </div>
      <a-pagination
        v-if="
          pagination?.total &&
          pagination.total > (pagination.pageSize ?? 10)
        "
        class="mt-4 justify-center pt-2"
        show-less-items
        :current="pagination.current"
        :page-size="pagination.pageSize"
        :total="pagination.total"
        :show-size-changer="false"
        @change="onMobilePaginationChange"
      />
    </a-spin>
  </div>
</template>
