<template>
  <a-table
    class="ant-table-striped"
    :columns="columns"
    :data-source="invoices"
    :loading="loading"
    :pagination="{ pageSize: 10 }"
    :row-class-name="(_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')"
    row-key="id"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'invoice_number'">
        <span>
          {{ record.reference_number || record.sale?.invoice_number || "N/A" }}
        </span>
      </template>

      <template v-if="column.key === 'date'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-if="column.key === 'amount'">
        <span class="font-medium text-red-600">
          ₱{{
            record.amount.toLocaleString("en-US", {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            })
          }}
        </span>
      </template>

      <template v-if="column.key === 'due_date'">
        <span :class="getDueDateClass(record.due_date)">
          {{ formatDate(record.due_date) }}
        </span>
      </template>

      <template v-if="column.key === 'days_overdue'">
        <span v-if="getDaysOverdue(record.due_date) > 0" class="text-red-600 font-medium">
          {{ getDaysOverdue(record.due_date) }} days
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
    sorter: true,
  },
  {
    title: "Amount",
    key: "amount",
    dataIndex: "amount",
    align: "right",
    sorter: true,
  },
  {
    title: "Due Date",
    key: "due_date",
    dataIndex: "due_date",
    sorter: true,
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

const formatDate = (date) => {
  if (!date) return "-";
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};

const getDaysOverdue = (dueDate) => {
  if (!dueDate) return 0;
  const due = new Date(dueDate);
  const now = new Date();
  const diffTime = now - due;
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  return diffDays > 0 ? diffDays : 0;
};

const getDueDateClass = (dueDate) => {
  const daysOverdue = getDaysOverdue(dueDate);
  if (daysOverdue > 0) return "text-red-600 font-medium";
  if (daysOverdue === 0) return "text-orange-600 font-medium";
  return "text-gray-600";
};
</script>
