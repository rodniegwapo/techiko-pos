<script setup>
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconTrash, IconEdit } from "@tabler/icons-vue";

const props = defineProps({
  categories: { type: Object, required: true },
  pagination: { type: Object, required: false, default: () => ({}) },
});

const emit = defineEmits(["handleTableChange"]);

const columns = [
  { title: "Category", dataIndex: "name", key: "name", align: "left" },
  {
    title: "Description",
    dataIndex: "description",
    key: "description",
    align: "left",
  },
  { title: "Action", key: "action", align: "center", width: "1%" },
];

const handleTableChange = (event) => {
  emit("handleTableChange", event);
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
  >
    <template #bodyCell="{ index, column, record }">
      <template v-if="column.key == 'action'">
        <div class="flex items-center gap-2">
          <icon-tooltip-button hover="group-hover:bg-blue-500" name="Edit Category">
            <IconEdit size="20" class="mx-auto" />
          </icon-tooltip-button>

          <icon-tooltip-button hover="group-hover:bg-red-500" name="Delete Category">
            <IconTrash size="20" class="mx-auto" />
          </icon-tooltip-button>
        </div>
      </template>
    </template>
  </a-table>
</template>
