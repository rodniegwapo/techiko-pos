<template>
  <a-table
    class="ant-table-striped"
    :columns="columns"
    :data-source="invoices"
    :loading="loading"
    :pagination="{ pageSize: 10 }"
    :row-class-name="(_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')"
    :row-expandable="(record) => record.installments?.length > 0"
    row-key="id"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'invoice_number'">
        <span>
          {{ record.reference_number || record.sale?.invoice_number || "N/A" }}
        </span>
        <a-tag v-if="record.installments?.length" color="blue" class="ml-2">
          {{ record.installments.length }} installments
        </a-tag>
      </template>

      <template v-if="column.key === 'date'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-if="column.key === 'amount'">
        <span class="text-gray-700">{{ peso(record.amount) }}</span>
      </template>

      <template v-if="column.key === 'remaining'">
        <span class="font-medium text-red-600">
          {{ peso(record.remaining ?? record.amount) }}
        </span>
      </template>

      <template v-if="column.key === 'due_date'">
        <span :class="dueClass(record)">
          {{ formatDate(nextDue(record)) }}
        </span>
        <div v-if="record.installments?.length" class="text-xs text-gray-400">
          next installment
        </div>
      </template>

      <template v-if="column.key === 'days_overdue'">
        <span v-if="record.days_overdue > 0" class="text-red-600 font-medium">
          {{ record.days_overdue }} days
        </span>
        <span v-else class="text-gray-400">-</span>
      </template>

      <template v-if="column.key === 'actions'">
        <div class="flex items-center justify-center gap-2">
          <IconTooltipButton
            hover="group-hover:bg-teal-500"
            name="Record Payment"
            @click="$emit('recordPayment', record)"
          >
            <IconCash size="20" class="mx-auto" />
          </IconTooltipButton>

          <IconTooltipButton
            hover="group-hover:bg-blue-500"
            name="View order"
            :disabled="!record.sale"
            @click="$emit('viewOrder', record)"
          >
            <IconListDetails size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>

    <template #expandedRowRender="{ record }">
      <a-table
        :columns="installmentColumns"
        :data-source="record.installments"
        :pagination="false"
        size="small"
        row-key="id"
      >
        <template #bodyCell="{ column, record: installment }">
          <template v-if="column.key === 'due_date'">
            {{ formatDate(installment.due_date) }}
          </template>
          <template v-else-if="column.key === 'amount'">
            {{ peso(installment.amount) }}
          </template>
          <template v-else-if="column.key === 'paid_amount'">
            {{ peso(installment.paid_amount) }}
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag v-if="installment.paid_at" color="success">Paid</a-tag>
            <a-tag v-else-if="installment.is_overdue" color="error">Overdue</a-tag>
            <a-tag v-else-if="Number(installment.paid_amount) > 0" color="warning">Partly paid</a-tag>
            <a-tag v-else>Due</a-tag>
          </template>
        </template>
      </a-table>
    </template>
  </a-table>
</template>

<script setup>
import { computed } from "vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconCash, IconListDetails } from "@tabler/icons-vue";

defineProps({
  invoices: Array,
  customer: Object,
  loading: Boolean,
});

defineEmits(["recordPayment", "viewOrder"]);

const columns = computed(() => [
  {
    title: "Invoice Number",
    key: "invoice_number",
    dataIndex: "reference_number",
  },
  {
    title: "Date",
    key: "date",
    dataIndex: "created_at",
  },
  {
    title: "Amount",
    key: "amount",
    dataIndex: "amount",
    align: "right",
  },
  {
    title: "Remaining",
    key: "remaining",
    dataIndex: "remaining",
    align: "right",
  },
  {
    title: "Due Date",
    key: "due_date",
    dataIndex: "due_date",
  },
  {
    title: "Days Overdue",
    key: "days_overdue",
    align: "right",
  },
  {
    title: "Actions",
    key: "actions",
    align: "center",
  },
]);

const installmentColumns = [
  { title: "#", dataIndex: "seq", key: "seq", width: 48 },
  { title: "Due", key: "due_date" },
  { title: "Amount", key: "amount", align: "right" },
  { title: "Paid", key: "paid_amount", align: "right" },
  { title: "Status", key: "status", align: "center" },
];

const peso = (v) =>
  `₱${Number(v || 0).toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;

const formatDate = (date) => {
  if (!date) return "-";
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};

// For an installment plan, what matters is the earliest installment still unpaid.
const nextDue = (record) => {
  const unpaid = (record.installments || []).find((i) => !i.paid_at);
  return unpaid ? unpaid.due_date : record.due_date;
};

const dueClass = (record) => {
  if (record.is_overdue) return "text-red-600 font-medium";
  const due = nextDue(record);
  if (due && new Date(due).toDateString() === new Date().toDateString()) {
    return "text-orange-600 font-medium";
  }
  return "text-gray-600";
};
</script>
