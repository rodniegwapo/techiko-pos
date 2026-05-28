<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import {
  IconEye,
  IconEdit,
  IconCheck,
  IconX,
  IconSend,
  IconTrash,
  IconFileText,
  IconWorld,
} from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { router } from "@inertiajs/vue3";

const { formatCurrency, formatDate, confirmDelete, showNotification } =
  useHelpers();
const page = usePage();
const isMdUp = useMediaQuery("(min-width: 768px)");

const emit = defineEmits(["handleTableChange", "refresh", "showDetails"]);

const props = defineProps({
  adjustments: {
    type: Object,
    default: () => ({}),
  },
  pagination: {
    type: Object,
    default: () => ({}),
  },
  loading: {
    type: Boolean,
    default: false,
  },
  isGlobalView: {
    type: Boolean,
    default: false,
  },
});

const showSuperUserDomain = computed(
  () => page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

// Simplified columns - only essential information
const columns = computed(() => {
  const baseColumns = [
    {
      title: "Adjustment #",
      dataIndex: "number",
      key: "number",
      align: "left",
    },
    {
      title: "Reason",
      dataIndex: "reason",
      key: "reason",
      align: "left",
    },
    {
      title: "Status",
      dataIndex: "status",
      key: "status",
      align: "left",
    },
    {
      title: "Items",
      dataIndex: "items",
      key: "items",
      align: "left",
    },
    {
      title: "Value",
      dataIndex: "value",
      key: "value",
      align: "left",
    },
  ];

  // Add domain column for super users only in global view
  if (showSuperUserDomain.value) {
    baseColumns.splice(2, 0, {
      title: "Domain",
      dataIndex: "domain",
      key: "domain",
      align: "left",
    });
  }

  baseColumns.push({ title: "Actions", key: "actions", align: "center", width: "1%" });
  
  return baseColumns;
});

const getStatusColor = (status) => {
  const colors = {
    draft: "blue",
    pending_approval: "orange",
    approved: "green",
    rejected: "red",
    completed: "purple",
  };
  return colors[status] || "default";
};

const showDetails = (adjustment) => {
  emit("showDetails", adjustment);
};

const getStatusText = (status) => {
  const texts = {
    draft: "Draft",
    pending_approval: "Pending Approval",
    approved: "Approved",
    rejected: "Rejected",
  };
  return texts[status] || status;
};

const getTypeColor = (type) => {
  const colors = {
    increase: "green",
    decrease: "red",
    recount: "blue",
  };
  return colors[type] || "default";
};

const viewAdjustment = (adjustment) => {
  router.visit(route("inventory.adjustments.show", adjustment.id));
};

const editAdjustment = (adjustment) => {
  router.visit(route("inventory.adjustments.edit", adjustment.id));
};

const submitForApproval = async (adjustment) => {
  try {
    await router.post(
      route("inventory.adjustments.submit", adjustment.id),
      {},
      {
        onSuccess: () => {
          showNotification(
            "success",
            "Success",
            "Adjustment submitted for approval"
          );
          emit("refresh");
        },
        onError: () => {
          showNotification("error", "Error", "Failed to submit adjustment");
        },
      }
    );
  } catch (error) {
    console.error("Submit error:", error);
  }
};

const approveAdjustment = async (adjustment) => {
  try {
    await router.post(
      route("inventory.adjustments.approve", adjustment.id),
      {},
      {
        onSuccess: () => {
          showNotification(
            "success",
            "Success",
            "Adjustment approved and processed"
          );
          emit("refresh");
        },
        onError: () => {
          showNotification("error", "Error", "Failed to approve adjustment");
        },
      }
    );
  } catch (error) {
    console.error("Approve error:", error);
  }
};

const rejectAdjustment = async (adjustment) => {
  try {
    await router.post(
      route("inventory.adjustments.reject", adjustment.id),
      {},
      {
        onSuccess: () => {
          showNotification("success", "Success", "Adjustment rejected");
          emit("refresh");
        },
        onError: () => {
          showNotification("error", "Error", "Failed to reject adjustment");
        },
      }
    );
  } catch (error) {
    console.error("Reject error:", error);
  }
};

const deleteAdjustment = (adjustment) => {
  confirmDelete(
    "inventory.adjustments.destroy",
    { stockAdjustment: adjustment.id },
    "Do you want to delete this adjustment?",
    "This action cannot be undone."
  );
};

const dataSource = computed(() => {
  return (
    props.adjustments?.data?.map((adjustment) => ({
      key: adjustment.id,
      id: adjustment.id,
      number: adjustment.adjustment_number,
      location: adjustment.location,
      type: adjustment.type,
      reason: adjustment.reason,
      status: adjustment.status,
      domain: adjustment.domain,
      value_change: adjustment.total_value_change,
      created_by: adjustment.created_by,
      approved_by: adjustment.approved_by,
      created_at: adjustment.created_at,
      approved_at: adjustment.approved_at,
      description: adjustment.description,
      items_count: adjustment.items_count, // ✅ Add items_count to top level
      adjustment: adjustment,
    })) || []
  );
});

function onMobilePaginationChange(pageNum) {
  emit("handleTableChange", {
    current: pageNum,
    pageSize: props.pagination?.pageSize ?? 10,
  });
}
</script>

<template>
  <a-table
    v-if="isMdUp"
    :columns="columns"
    :data-source="dataSource"
    :pagination="pagination"
    :loading="loading"
    @change="$emit('handleTableChange', $event)"
  >
    <!-- Adjustment Number Column -->
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'number'">
        <div>
          <p
            class="font-mono font-semibold text-blue-600 cursor-pointer hover:underline"
            @click="viewAdjustment(record.adjustment)"
          >
            {{ record.number }}
          </p>
          <p
            v-if="record.description"
            class="text-xs text-gray-500 mt-1 truncate"
          >
            {{ record.description }}
          </p>
        </div>
      </template>


      <!-- Domain Column -->
      <template v-else-if="column.key === 'domain'">
        <div class="flex items-center justify-center">
          <IconWorld class="mr-1" size="16" />
          <span class="text-sm font-medium">{{ record.domain || 'N/A' }}</span>
        </div>
      </template>

      <!-- Reason Column -->
      <template v-else-if="column.key === 'reason'">
        <div>
          <p class="text-sm text-gray-900">
            {{ record.reason || "No reason provided" }}
          </p>
        </div>
      </template>

      <!-- Status Column -->
      <template v-else-if="column.key === 'status'">
        <a-tag class="w-fit" :color="getStatusColor(record.status)">
          {{ getStatusText(record.status) }}
        </a-tag>
      </template>

      <!-- Items Column -->
      <template v-else-if="column.key === 'items'">
        <div class="font-semibold">{{ record?.items_count }}</div>
      </template>

      <!-- Value Column -->
      <template v-else-if="column.key === 'value'">
        <div>
          <p
            class="font-semibold"
            :class="{
              'text-green-600': record.value_change > 0,
              'text-red-600': record.value_change < 0,
              'text-gray-600': record.value_change === 0,
            }"
          >
            {{ record.value_change > 0 ? "+" : ""
            }}{{ formatCurrency(record.value_change) }}
          </p>
        </div>
      </template>

      <!-- Actions Column -->
      <template v-else-if="column.key === 'actions'">
        <div class="flex justify-center gap-1">
          <!-- View Details (Always available) -->
          <IconTooltipButton
            name="View Details"
            @click="showDetails(record.adjustment)"
          >
            <IconEye :size="20" class="mx-auto" />
          </IconTooltipButton>

          <!-- Submit for Approval (Draft status only) -->
          <IconTooltipButton
            v-if="record.status === 'draft'"
            name="Submit for Approval"
            @click="submitForApproval(record.adjustment)"
          >
            <IconSend :size="20" class="mx-auto" />
          </IconTooltipButton>

          <!-- Approve (Pending approval status only) -->
          <IconTooltipButton
            v-if="record.status === 'pending_approval'"
            name="Approve"
            @click="approveAdjustment(record.adjustment)"
          >
            <IconCheck :size="20" class="mx-auto" />
          </IconTooltipButton>

          <!-- Reject (Pending approval status only) -->
          <IconTooltipButton
            v-if="record.status === 'pending_approval'"
            name="Reject"
            @click="rejectAdjustment(record.adjustment)"
          >
            <IconX :size="20" class="mx-auto" />
          </IconTooltipButton>

          <!-- Edit (Draft status only) -->
          <IconTooltipButton
            v-if="record.status === 'draft'"
            name="Edit"
            @click="editAdjustment(record.adjustment)"
          >
            <IconEdit :size="20" class="mx-auto" />
          </IconTooltipButton>

          <!-- Delete (Draft status only) -->
          <IconTooltipButton
            v-if="record.status === 'draft'"
            name="Delete"
            @click="deleteAdjustment(record.adjustment)"
          >
            <IconTrash :size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>

    <!-- Empty State -->
    <template #emptyText>
      <div class="text-center py-8">
        <IconFileText :size="48" class="mx-auto text-gray-400 mb-4" />
        <p class="text-gray-500">No stock adjustments found</p>
        <p class="text-sm text-gray-400">
          Create your first adjustment to get started
        </p>
      </div>
    </template>
  </a-table>

  <div v-else class="px-2 py-2 md:px-0">
    <a-spin :spinning="loading">
      <div
        v-if="!dataSource.length"
        class="py-12 text-center text-sm text-gray-500"
      >
        <IconFileText :size="48" class="mx-auto mb-4 text-gray-400" />
        <p>No stock adjustments found</p>
        <p class="text-xs text-gray-400">
          Create your first adjustment to get started
        </p>
      </div>
      <div v-else class="flex flex-col gap-3">
        <div
          v-for="record in dataSource"
          :key="record.id"
          class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
        >
          <div class="flex flex-wrap items-start justify-between gap-2 px-4 py-3">
            <div>
              <p
                class="cursor-pointer font-mono text-sm font-semibold text-blue-600"
                @click="viewAdjustment(record.adjustment)"
              >
                {{ record.number }}
              </p>
              <p class="text-xs text-gray-500">
                {{ formatDate(record.created_at) }}
              </p>
            </div>
            <a-tag class="m-0 w-fit" :color="getStatusColor(record.status)">
              {{ getStatusText(record.status) }}
            </a-tag>
          </div>

          <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
            <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
              <span class="text-gray-500">Reason</span>
              <span class="text-right font-medium text-gray-900">
                {{ record.reason || "No reason provided" }}
              </span>
              <span class="text-gray-500">Location</span>
              <span class="text-right font-medium text-gray-900">
                {{ record.location?.name || "N/A" }}
              </span>
              <span class="text-gray-500">Items</span>
              <span class="text-right font-semibold text-gray-900">
                {{ record.items_count ?? 0 }}
              </span>
              <span class="text-gray-500">Value</span>
              <span
                class="text-right font-semibold"
                :class="{
                  'text-green-600': record.value_change > 0,
                  'text-red-600': record.value_change < 0,
                  'text-gray-600': record.value_change === 0,
                }"
              >
                {{ record.value_change > 0 ? "+" : ""
                }}{{ formatCurrency(record.value_change) }}
              </span>
              <template v-if="showSuperUserDomain">
                <span class="text-gray-500">Domain</span>
                <span
                  class="flex min-w-0 items-center justify-end gap-1 truncate font-medium text-gray-900"
                >
                  <IconWorld size="16" class="shrink-0" />
                  {{ record.domain || "N/A" }}
                </span>
              </template>
            </div>
          </div>

          <div class="border-t border-gray-100 px-4 py-3">
            <div class="flex flex-col gap-2">
              <a-button
                class="flex items-center justify-center gap-2"
                @click="showDetails(record.adjustment)"
              >
                <template #icon><IconEye size="18" /></template>
                View details
              </a-button>
              <a-button
                v-if="record.status === 'draft'"
                class="flex items-center justify-center gap-2"
                @click="submitForApproval(record.adjustment)"
              >
                <template #icon><IconSend size="18" /></template>
                Submit for approval
              </a-button>
              <a-button
                v-if="record.status === 'pending_approval'"
                type="primary"
                class="flex items-center justify-center gap-2"
                @click="approveAdjustment(record.adjustment)"
              >
                <template #icon><IconCheck size="18" /></template>
                Approve
              </a-button>
              <a-button
                v-if="record.status === 'pending_approval'"
                danger
                class="flex items-center justify-center gap-2"
                @click="rejectAdjustment(record.adjustment)"
              >
                <template #icon><IconX size="18" /></template>
                Reject
              </a-button>
              <a-button
                v-if="record.status === 'draft'"
                class="flex items-center justify-center gap-2"
                @click="editAdjustment(record.adjustment)"
              >
                <template #icon><IconEdit size="18" /></template>
                Edit
              </a-button>
              <a-button
                v-if="record.status === 'draft'"
                danger
                class="flex items-center justify-center gap-2"
                @click="deleteAdjustment(record.adjustment)"
              >
                <template #icon><IconTrash size="18" /></template>
                Delete
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

<style scoped>
.ant-table-tbody > tr > td {
  padding: 12px 8px;
}

.ant-table-thead > tr > th {
  background-color: #fafafa;
  font-weight: 600;
}
</style>
