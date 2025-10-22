<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconTrash, IconEdit, IconWorld } from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const emit = defineEmits(["handleTableChange"]);
const { confirmDelete } = useHelpers();
const { formData, openModal, isEdit,spinning } = useGlobalVariables();
const { getRoute } = useDomainRoutes();
const page = usePage();

const props = defineProps({
  categories: { type: Object, required: true },
  pagination: { type: Object, required: false, default: () => ({}) },
  isGlobalView: { type: Boolean, default: false },
});

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

  // Add domain column for super users only
  if (page.props.auth?.user?.data?.is_super_user) {
    baseColumns.splice(1, 0, {
      title: "Domain",
      dataIndex: "domain",
      key: "domain",
      align: "left",
    });
  }

  baseColumns.push({ title: "Action", key: "action", align: "center", width: "1%" });
  
  return baseColumns;
});

const handleTableChange = (event) => {
  emit("handleTableChange", event);
};

const handleDeleteCategory = (record) => {
  confirmDelete(
    "categories.destroy",
    { category: record.id },
    "Do you want to delete this item ?"
  );
};

const handleClickEdit = (record) => {
  openModal.value = true;
  formData.value = record;
  isEdit.value = true
};
</script>

<template>
  <a-table
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
    <template #bodyCell="{ index, column, record }">
      <template v-if="column.key == 'domain'">
        <div class="flex items-center">
          <IconWorld class="mr-1" size="16" />
          <span class="text-sm font-medium">{{ record.domain || 'N/A' }}</span>
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
</template>
