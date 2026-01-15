<template>
  <a-table
    :columns="columns"
    :data-source="history"
    :loading="loading"
    :pagination="{ pageSize: 20 }"
    row-key="id"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-if="column.key === 'type'">
        <a-tag :color="getTypeColor(record.transaction_type)">
          {{ record.transaction_type.toUpperCase() }}
        </a-tag>
      </template>

      <template v-if="column.key === 'amount'">
        <span :class="getAmountClass(record.transaction_type)">
          {{ record.transaction_type === 'payment' || record.transaction_type === 'refund' ? '-' : '+' }}
          ₱{{ record.amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
        </span>
      </template>

      <template v-if="column.key === 'balance_after'">
        ₱{{ record.balance_after.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
      </template>

      <template v-if="column.key === 'reference'">
        <span v-if="record.reference_number">{{ record.reference_number }}</span>
        <span v-else class="text-gray-400">-</span>
      </template>

      <template v-if="column.key === 'status'">
        <a-tag v-if="record.paid_at" color="success">Paid</a-tag>
        <a-tag v-else-if="record.isOverdue" color="error">Overdue</a-tag>
        <a-tag v-else color="default">Pending</a-tag>
      </template>
    </template>
  </a-table>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  history: Array,
  loading: Boolean,
});

const columns = computed(() => [
  {
    title: "Date",
    key: "date",
    dataIndex: "created_at",
    sorter: true,
  },
  {
    title: "Type",
    key: "type",
    dataIndex: "transaction_type",
  },
  {
    title: "Amount",
    key: "amount",
    dataIndex: "amount",
    align: "right",
  },
  {
    title: "Balance After",
    key: "balance_after",
    dataIndex: "balance_after",
    align: "right",
  },
  {
    title: "Reference",
    key: "reference",
    dataIndex: "reference_number",
  },
  {
    title: "Status",
    key: "status",
  },
]);

const formatDate = (date) => {
  if (!date) return "-";
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const getTypeColor = (type) => {
  const colors = {
    credit: "blue",
    payment: "green",
    adjustment: "orange",
    refund: "purple",
  };
  return colors[type] || "default";
};

const getAmountClass = (type) => {
  if (type === "payment" || type === "refund") {
    return "text-green-600";
  }
  return "text-red-600";
};
</script>
