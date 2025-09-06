<script setup>
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconTrash, IconEdit, IconCurrencyPeso } from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const emit = defineEmits(["handleTableChange"]);
const { confirmDelete } = useHelpers();
const { formData, openModal, isEdit, spinning } = useGlobalVariables();

const props = defineProps({
  products: { type: Object, required: true },
  pagination: { type: Object, required: false, default: () => ({}) },
});

const columns = [
  { title: "Avatar", dataIndex: "avatar", key: "avatar", align: "left" },
  { title: "Category", dataIndex: "category", key: "category", align: "left" },
  { title: "Product", dataIndex: "name", key: "name", align: "left" },
  {
    title: "Price",
    dataIndex: "price",
    key: "price",
    align: "left",
  },

  { title: "Cost", dataIndex: "cost", key: "cost", align: "left" },
  {
    title: "SKU",
    dataIndex: "SKU",
    key: "SKU",
    align: "left",
  },
  { title: "Action", key: "action", align: "center", width: "1%" },
];

const handleTableChange = (event) => {
  emit("handleTableChange", event);
};

const handleDeleteCategory = (record) => {
  confirmDelete(
    "categories.destroy",
    { id: record.id },
    "Do you want to delete this item ?"
  );
};

const handleClickEdit = (record) => {
  openModal.value = true;
  formData.value = record;
  isEdit.value = true;
};
</script>

<template>
  <a-table
    class="ant-table-striped"
    :columns="columns"
    :data-source="products"
    :row-class-name="
      (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
    "
    @change="handleTableChange"
    :pagination="pagination"
    :loading="spinning"
  >
    <template #bodyCell="{ index, column, record }">
      <template v-if="column.key == 'avatar'">
        <a-avatar
          v-if="record.representation_type == 'color'"
          shape="circle"
          size="large"
          :src="`https://ui-avatars.com/api/?name=${record.name}&background=${record.representation}&color=ffff`"
        >
        </a-avatar
        ><a-avatar
          v-else
          shape="circle"
          size="large"
          :src="record.representation"
        >
        </a-avatar>
      </template>

      <template v-if="column.key == 'category'">
        {{ record.category?.name }}
      </template>

      <template v-if="column.key == 'price'">
        <div class="flex items-center">
          <IconCurrencyPeso /> {{ record.price }}
        </div>
      </template>
      <template v-if="column.key == 'cost'">
        <div class="flex items-center">
          <IconCurrencyPeso /> {{ record.cost }}
        </div>
      </template>

      <template v-if="column.key == 'SKU'">
        <div class="flex items-center">
          <a-tag color="blue">
            <div class="flex items-center">{{ record.SKU }}</div></a-tag
          >
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
