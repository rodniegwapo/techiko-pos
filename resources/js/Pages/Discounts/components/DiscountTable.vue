<script setup>
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import {
  IconTrash,
  IconEdit,
  IconCurrencyPeso,
  IconEye,
} from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import dayjs from "dayjs";

const emit = defineEmits(["handleTableChange", "selectedDiscount"]);
const { confirmDelete, formattedTotal, formattedPercent } = useHelpers();
const { formData, openModal, isEdit, spinning, openViewModal } =
  useGlobalVariables();

const props = defineProps({
  products: { type: Object, required: true },
  pagination: { type: Object, required: false, default: () => ({}) },
});

const columns = [
  {
    title: "Dicount Name",
    dataIndex: "name",
    key: "name",
    align: "left",
  },
  {
    title: "Type",
    dataIndex: "type",
    key: "type",
    align: "left",
  },
  {
    title: "Value",
    dataIndex: "value",
    key: "value",
    align: "left",
  },
  {
    title: "Start Date",
    dataIndex: "start_date",
    key: "start_date",
    align: "left",
  },
  {
    title: "End Date",
    dataIndex: "end_date",
    key: "end_date",

    align: "left",
  },
  { title: "Action", key: "action", align: "center", width: "1%" },
];

const handleTableChange = (event) => {
  emit("handleTableChange", event);
};

const handleDelete = (record) => {
  confirmDelete(
    "products.discounts.destroy",
    { id: record.id },
    "Do you want to delete this item ?"
  );
};

const handleClickEdit = (record) => {
  formData.value = {
    ...record,
    start_date: dayjs(record.start_date),
    end_date: dayjs(record.end_date),
  };
  isEdit.value = true;
  openModal.value = true;
};

const handleViewDetail = (record) => {
  openViewModal.value = true;
  emit("selectedDiscount", record);
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
      <template v-if="column.key == 'value'">
        <span v-if="record.type == 'Amount'">
          {{ formattedTotal(record.value) }}
        </span>
        <span v-else>{{ formattedPercent(record.value) }}</span>
      </template>

      <template v-if="column.key == 'start_date'">
        {{ dayjs(record.start_date).format("MMM DD YYYY hh:mm:ss a") }}
      </template>
      <template v-if="column.key == 'end_date'">
        {{ dayjs(record.end_date).format("MMM DD YYYY hh:mm:ss a") }}
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
            @click="handleDelete(record)"
          >
            <IconTrash size="20" class="mx-auto" />
          </icon-tooltip-button>

          <icon-tooltip-button
            hover="group-hover:bg-[#3379B4]"
            name="View Discount"
            @click="handleViewDetail(record)"
          >
            <IconEye size="20" class="mx-auto" />
          </icon-tooltip-button>
        </div>
      </template>
    </template>
  </a-table>
</template>
