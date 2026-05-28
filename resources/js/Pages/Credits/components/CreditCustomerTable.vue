<template>
  <a-table
    v-if="isMdUp"
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
        <div class="font-medium">₱{{ formatAmount(record.credit_limit) }}</div>
      </template>

      <template v-if="column.key === 'credit_balance'">
        <div class="font-medium" :class="record.credit_balance > 0 ? 'text-red-600' : 'text-gray-500'">
          ₱{{ formatAmount(record.credit_balance) }}
        </div>
      </template>

      <template v-if="column.key === 'available_credit'">
        <div class="font-medium text-green-600">
          ₱{{ formatAmount(record.available_credit) }}
        </div>
      </template>

      <template v-if="column.key === 'overdue_amount'">
        <div v-if="record.overdue_amount > 0" class="font-medium text-red-600">
          ₱{{ formatAmount(record.overdue_amount) }}
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

          <IconTooltipButton
            v-if="canRecordPayment(record)"
            hover="group-hover:bg-teal-500"
            name="Record Payment"
            @click="$emit('recordPayment', record)"
          >
            <IconCash size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>
  </a-table>

  <div v-else class="px-2 py-2 md:px-0">
    <a-spin :spinning="loading">
      <div
        v-if="!customers?.length"
        class="py-12 text-center text-sm text-gray-500"
      >
        No credit accounts found.
      </div>
      <div v-else class="flex flex-col gap-3">
        <div
          v-for="record in customers"
          :key="record.id"
          class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
        >
          <div class="flex gap-3 px-4 py-3">
            <a-avatar
              class="shrink-0"
              :style="{ backgroundColor: getAvatarColor(record.name) }"
            >
              {{ getInitials(record.name) }}
            </a-avatar>
            <div class="min-w-0 flex-1">
              <div class="truncate text-base font-semibold text-gray-900">
                {{ record.name }}
              </div>
              <div class="mt-1 text-sm text-gray-600">
                {{ record.email || record.phone || 'N/A' }}
              </div>
              <div class="mt-2">
                <a-tag v-if="!record.credit_enabled" class="m-0" color="default">Disabled</a-tag>
                <a-tag v-else-if="record.overdue_amount > 0" class="m-0" color="error">Overdue</a-tag>
                <a-tag v-else-if="record.credit_balance >= record.credit_limit" class="m-0" color="warning">At Limit</a-tag>
                <a-tag v-else class="m-0" color="success">Good Standing</a-tag>
              </div>
            </div>
          </div>

          <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
            <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
              <span class="text-gray-500">Credit Limit</span>
              <span class="text-right font-medium text-gray-900">
                ₱{{ formatAmount(record.credit_limit) }}
              </span>
              <span class="text-gray-500">Balance</span>
              <span
                class="text-right font-semibold"
                :class="record.credit_balance > 0 ? 'text-red-600' : 'text-gray-900'"
              >
                ₱{{ formatAmount(record.credit_balance) }}
              </span>
              <span class="text-gray-500">Available</span>
              <span class="text-right font-semibold text-green-600">
                ₱{{ formatAmount(record.available_credit) }}
              </span>
              <span class="text-gray-500">Overdue</span>
              <span
                class="text-right font-medium"
                :class="record.overdue_amount > 0 ? 'text-red-600' : 'text-gray-400'"
              >
                ₱{{ formatAmount(record.overdue_amount) }}
              </span>
            </div>
          </div>

          <div class="border-t border-gray-100 px-4 py-3">
            <div class="flex flex-col gap-2">
              <a-button
                class="flex items-center justify-center gap-2"
                @click="$emit('view', record)"
              >
                <template #icon>
                  <IconEye size="18" />
                </template>
                View details
              </a-button>
              <a-button
                class="flex items-center justify-center gap-2"
                @click="$emit('editLimit', record)"
              >
                <template #icon>
                  <IconEdit size="18" />
                </template>
                Edit credit limit
              </a-button>
              <a-button
                v-if="canRecordPayment(record)"
                class="flex items-center justify-center gap-2"
                @click="$emit('recordPayment', record)"
              >
                <template #icon>
                  <IconCash size="18" />
                </template>
                Record payment
              </a-button>
            </div>
          </div>
        </div>
      </div>
      <a-pagination
        v-if="
          pagination?.total &&
          pagination.total > (pagination.pageSize ?? 10)
        "
        class="mt-4 justify-center pt-2"
        show-less-items
        :current="pagination.current"
        :page-size="pagination.pageSize"
        :total="pagination.total"
        :show-size-changer="false"
        @change="onMobilePaginationChange"
      />
    </a-spin>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { IconEye, IconEdit, IconCash } from "@tabler/icons-vue";

const isMdUp = useMediaQuery("(min-width: 768px)");

const props = defineProps({
  customers: Array,
  loading: Boolean,
  pagination: Object,
});

const emit = defineEmits(["change", "view", "editLimit", "recordPayment"]);

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
    width: 150,
  },
]);

const formatAmount = (value) =>
  (value || 0).toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

const canRecordPayment = (record) =>
  Boolean(record.credit_enabled) && Number(record.credit_balance || 0) > 0;

const handleChange = (pag) => {
  emit("change", pag);
};

function onMobilePaginationChange(pageNum) {
  emit("change", {
    current: pageNum,
    pageSize: props.pagination?.pageSize ?? 10,
  });
}

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
