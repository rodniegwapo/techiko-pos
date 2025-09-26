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

const emit = defineEmits(["handleTableChange", "selectedMandatoryDiscount"]);
const { confirmDelete, formattedTotal, formattedPercent } = useHelpers();
const { formData, openModal, isEdit, spinning, openViewModal } =
  useGlobalVariables();

const props = defineProps({
  products: { type: Object, required: true },
  pagination: { type: Object, required: false, default: () => ({}) },
});

const columns = [
  {
    title: "Discount Name",
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
    title: "Status",
    dataIndex: "is_active",
    key: "is_active",
    align: "center",
  },
  { title: "Action", key: "action", align: "center", width: "1%" },
];

const handleTableChange = (event) => {
  emit("handleTableChange", event);
};

const handleDelete = (record) => {
  confirmDelete(
    "mandatory-discounts.destroy",
    { mandatory_discount: record.id },
    "Do you want to delete this mandatory discount?"
  );
};

const handleClickEdit = (record) => {
  formData.value = {
    ...record,
  };
  isEdit.value = true;
  openModal.value = true;
};

const handleViewDetail = (record) => {
  openViewModal.value = true;
  emit("selectedMandatoryDiscount", record);
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
      <template v-if="column.key == 'type'">
        <a-tag class="w-fit" :color="record.type === 'percentage' ? 'blue' : 'green'">
          {{ record.type === 'percentage' ? 'Percentage' : 'Amount' }}
        </a-tag>
      </template>

      <template v-if="column.key == 'value'">
        <span v-if="record.type == 'amount'">
          {{ formattedTotal(record.value) }}
        </span>
        <span v-else>{{ formattedPercent(record.value) }}</span>
      </template>

      <template v-if="column.key == 'is_active'">
        <a-tag class="w-fit" :color="record.is_active ? 'green' : 'red'">
          {{ record.is_active ? 'Active' : 'Inactive' }}
        </a-tag>
      </template>

      <template v-if="column.key == 'action'">
        <div class="flex items-center gap-2">
          <icon-tooltip-button
            hover="group-hover:bg-blue-500"
            name="Edit Mandatory Discount"
            @click="handleClickEdit(record)"
          >
            <IconEdit size="20" class="mx-auto" />
          </icon-tooltip-button>

          <icon-tooltip-button
            hover="group-hover:bg-red-500"
            name="Delete Mandatory Discount"
            @click="handleDelete(record)"
          >
            <IconTrash size="20" class="mx-auto" />
          </icon-tooltip-button>

          <icon-tooltip-button
            hover="group-hover:bg-[#3379B4]"
            name="View Mandatory Discount"
            @click="handleViewDetail(record)"
          >
            <IconEye size="20" class="mx-auto" />
          </icon-tooltip-button>
        </div>
      </template>
    </template>
  </a-table>
</template>
