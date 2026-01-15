<template>
  <a-table
    class="ant-table-striped"
    :columns="columns"
    :data-source="customers"
    :row-class-name="
      (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
    "
    :loading="loading"
    :pagination="pagination"
    row-key="id"
    @change="handleChange"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'name'">
        <div class="flex items-center">
          <a-avatar class="mr-3" :style="{ backgroundColor: getAvatarColor(record.name) }">
            {{ getInitials(record.name) }}
          </a-avatar>
          <div>
            <div class="font-medium text-gray-900">{{ record.name }}</div>
            <div class="text-sm text-gray-500">{{ record.email || record.phone || 'N/A' }}</div>
          </div>
        </div>
      </template>

      <template v-if="column.key === 'credit_status'">
        <a-tag v-if="!record.credit_enabled" color="default">Disabled</a-tag>
        <a-tag v-else-if="record.overdue_amount > 0" color="error">Overdue</a-tag>
        <a-tag v-else-if="record.credit_balance >= record.credit_limit" color="warning">At Limit</a-tag>
        <a-tag v-else color="success">Good Standing</a-tag>
      </template>

      <template v-if="column.key === 'credit_limit'">
        <div class="font-medium">₱{{ (record.credit_limit || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</div>
      </template>

      <template v-if="column.key === 'credit_balance'">
        <div class="font-medium" :class="record.credit_balance > 0 ? 'text-red-600' : 'text-gray-500'">
          ₱{{ (record.credit_balance || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
        </div>
      </template>

      <template v-if="column.key === 'available_credit'">
        <div class="font-medium text-green-600">
          ₱{{ (record.available_credit || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
        </div>
      </template>

      <template v-if="column.key === 'overdue_amount'">
        <div v-if="record.overdue_amount > 0" class="font-medium text-red-600">
          ₱{{ record.overdue_amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
        </div>
        <div v-else class="text-gray-400">₱0.00</div>
      </template>

      <template v-if="column.key === 'actions'">
        <div class="flex items-center gap-2">
          <IconTooltipButton
            hover="group-hover:bg-blue-500"
            name="View Credit Details"
            @click="$emit('view', record)"
          >
            <IconEye size="20" class="mx-auto" />
          </IconTooltipButton>

          <IconTooltipButton
            hover="group-hover:bg-green-500"
            name="Edit Credit Limit"
            @click="$emit('editLimit', record)"
          >
            <IconEdit size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>
  </a-table>
</template>

<script setup>
import { computed } from "vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconEye, IconEdit } from "@tabler/icons-vue";

const props = defineProps({
  customers: Array,
  loading: Boolean,
  pagination: Object,
});

const emit = defineEmits(["change", "view", "editLimit"]);

const columns = computed(() => [
  {
    title: "Customer",
    key: "name",
    dataIndex: "name",
    sorter: true,
  },
  {
    title: "Status",
    key: "credit_status",
    dataIndex: "credit_status",
  },
  {
    title: "Credit Limit",
    key: "credit_limit",
    dataIndex: "credit_limit",
    sorter: true,
    align: "right",
  },
  {
    title: "Balance",
    key: "credit_balance",
    dataIndex: "credit_balance",
    sorter: true,
    align: "right",
  },
  {
    title: "Available Credit",
    key: "available_credit",
    dataIndex: "available_credit",
    sorter: true,
    align: "right",
  },
  {
    title: "Overdue",
    key: "overdue_amount",
    dataIndex: "overdue_amount",
    sorter: true,
    align: "right",
  },
  {
    title: "Actions",
    key: "actions",
    align: "center",
    width: 120,
  },
]);

const handleChange = (pag, filters, sorter) => {
  emit("change", { pag, filters, sorter });
};

const getInitials = (name) => {
  if (!name) return "?";
  const parts = name.split(" ");
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  return name.substring(0, 2).toUpperCase();
};

const getAvatarColor = (name) => {
  const colors = [
    "#f56a00",
    "#7265e6",
    "#ffbf00",
    "#00a2ae",
    "#87d068",
    "#108ee9",
  ];
  let hash = 0;
  for (let i = 0; i < name.length; i++) {
    hash = name.charCodeAt(i) + ((hash << 5) - hash);
  }
  return colors[Math.abs(hash) % colors.length];
};
</script>
