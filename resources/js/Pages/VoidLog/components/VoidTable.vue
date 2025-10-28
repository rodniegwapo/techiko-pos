<script setup>
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconTrash, IconEdit, IconCurrencyPeso } from "@tabler/icons-vue";

import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePage } from "@inertiajs/vue3";
import dayjs from "dayjs";

const page = usePage();

const { confirmDelete,formattedTotal } = useHelpers();
const { formData, openModal, isEdit, spinning } = useGlobalVariables();

defineEmits(["handleTableChange"]);

const props = defineProps({
  pagination: {
    type: Object,
    default: {},
  },
  isGlobalView: {
    type: Boolean,
    default: false,
  },
});

const columns = [
  { title: "User", dataIndex: "user", key: "user", align: "left" },
  { title: "Product", dataIndex: "product", key: "product", align: "left" },
  {
    title: "Approved By",
    dataIndex: "approved_by",
    key: "approved_by",
    align: "left",
  },
  { title: "Amount", dataIndex: "amount", key: "amount", align: "left" },
  {
    title: "Transaction Date",
    dataIndex: "created_at",
    key: "created_at",
    align: "left",
  },
  // Add domain column for super users only in global view
  ...(page.props.auth?.user?.data?.is_super_user && props.isGlobalView ? [{
    title: "Domain",
    dataIndex: "domain",
    key: "domain",
    align: "left",
    sorter: (a, b) => (a.domain || '').localeCompare(b.domain || ''),
  }] : []),
];
</script>

<template>
  <a-table
    class="ant-table-striped"
    :columns="columns"
    :data-source="page.props?.items?.data ?? []"
    :row-class-name="
      (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
    "
    @change="$emit('handleTableChange', $event)"
    :pagination="pagination"
    :loading="spinning"
  >
    <template #bodyCell="{ index, column, record }">
      <template v-if="column.key == 'amount'">
        {{  formattedTotal(Number(record?.amount)) }}
      </template>
      <template v-if="column.key == 'user'">
        {{ record?.user.name }}
      </template>
      <template v-if="column.key == 'product'">
        {{ record?.sale_item?.product?.name }}
      </template>
      <template v-if="column.key == 'approved_by'">
        {{ record?.approver?.name }}
      </template>
      <template v-if="column.key == 'created_at'">
        {{ dayjs(record.created_at).format('dddd, MMMM D, YYYY HH:mm:ss') }}
      </template>
      <template v-if="column.key == 'domain'">
        {{ record?.domain || 'N/A' }}
      </template>
    </template>
  </a-table>
</template>
