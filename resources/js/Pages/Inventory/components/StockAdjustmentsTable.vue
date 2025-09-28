<script setup>
import { computed } from "vue";
import { 
  EyeOutlined,
  EditOutlined,
  CheckOutlined,
  CloseOutlined,
  SendOutlined,
  DeleteOutlined,
  FileTextOutlined
} from "@ant-design/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { router } from "@inertiajs/vue3";

const { formatCurrency, formatDate, confirmDelete, showNotification } = useHelpers();

const emit = defineEmits(["handleTableChange", "refresh"]);

const props = defineProps({
  adjustments: {
    type: Object,
    default: () => ({}),
  },
  pagination: {
    type: Object,
    default: () => ({}),
  },
});

const columns = [
  { title: "Adjustment #", dataIndex: "number", key: "number", align: "left", width: "15%" },
  { title: "Location", dataIndex: "location", key: "location", align: "left", width: "15%" },
  { title: "Type & Reason", dataIndex: "type_reason", key: "type_reason", align: "left", width: "20%" },
  { title: "Status", dataIndex: "status", key: "status", align: "center", width: "12%" },
  { title: "Value Change", dataIndex: "value", key: "value", align: "right", width: "12%" },
  { title: "Created By", dataIndex: "created_by", key: "created_by", align: "left", width: "12%" },
  { title: "Date", dataIndex: "date", key: "date", align: "center", width: "12%" },
  { title: "Actions", key: "actions", align: "center", width: "15%" },
];

const getStatusColor = (status) => {
  const colors = {
    draft: 'default',
    pending_approval: 'processing',
    approved: 'success',
    rejected: 'error',
  };
  return colors[status] || 'default';
};

const getStatusText = (status) => {
  const texts = {
    draft: 'Draft',
    pending_approval: 'Pending Approval',
    approved: 'Approved',
    rejected: 'Rejected',
  };
  return texts[status] || status;
};

const getTypeColor = (type) => {
  const colors = {
    increase: 'green',
    decrease: 'red',
    recount: 'blue',
  };
  return colors[type] || 'default';
};

const viewAdjustment = (adjustment) => {
  router.visit(route('inventory.adjustments.show', adjustment.id));
};

const editAdjustment = (adjustment) => {
  router.visit(route('inventory.adjustments.edit', adjustment.id));
};

const submitForApproval = async (adjustment) => {
  try {
    await router.post(route('inventory.adjustments.submit', adjustment.id), {}, {
      onSuccess: () => {
        showNotification('success', 'Success', 'Adjustment submitted for approval');
        emit('refresh');
      },
      onError: () => {
        showNotification('error', 'Error', 'Failed to submit adjustment');
      },
    });
  } catch (error) {
    console.error('Submit error:', error);
  }
};

const approveAdjustment = async (adjustment) => {
  try {
    await router.post(route('inventory.adjustments.approve', adjustment.id), {}, {
      onSuccess: () => {
        showNotification('success', 'Success', 'Adjustment approved and processed');
        emit('refresh');
      },
      onError: () => {
        showNotification('error', 'Error', 'Failed to approve adjustment');
      },
    });
  } catch (error) {
    console.error('Approve error:', error);
  }
};

const rejectAdjustment = async (adjustment) => {
  try {
    await router.post(route('inventory.adjustments.reject', adjustment.id), {}, {
      onSuccess: () => {
        showNotification('success', 'Success', 'Adjustment rejected');
        emit('refresh');
      },
      onError: () => {
        showNotification('error', 'Error', 'Failed to reject adjustment');
      },
    });
  } catch (error) {
    console.error('Reject error:', error);
  }
};

const deleteAdjustment = (adjustment) => {
  confirmDelete(
    'inventory.adjustments.destroy',
    { stockAdjustment: adjustment.id },
    'Do you want to delete this adjustment?',
    'This action cannot be undone.'
  );
};

const dataSource = computed(() => {
  return props.adjustments?.data?.map(adjustment => ({
    key: adjustment.id,
    id: adjustment.id,
    number: adjustment.adjustment_number,
    location: adjustment.location,
    type: adjustment.type,
    reason: adjustment.reason,
    status: adjustment.status,
    value_change: adjustment.total_value_change,
    created_by: adjustment.created_by,
    approved_by: adjustment.approved_by,
    created_at: adjustment.created_at,
    approved_at: adjustment.approved_at,
    description: adjustment.description,
    adjustment: adjustment,
  })) || [];
});
</script>

<template>
  <a-table
    :columns="columns"
    :data-source="dataSource"
    :pagination="pagination"
    :loading="false"
    @change="$emit('handleTableChange', $event)"
    size="middle"
    :scroll="{ x: 1200 }"
  >
    <!-- Adjustment Number Column -->
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'number'">
        <div>
          <p class="font-mono font-semibold text-blue-600 cursor-pointer hover:underline" 
             @click="viewAdjustment(record.adjustment)">
            {{ record.number }}
          </p>
          <p v-if="record.description" class="text-xs text-gray-500 mt-1 truncate">
            {{ record.description }}
          </p>
        </div>
      </template>

      <!-- Location Column -->
      <template v-else-if="column.key === 'location'">
        <div>
          <p class="font-medium text-sm">{{ record.location?.name || 'Unknown' }}</p>
          <p class="text-xs text-gray-500">{{ record.location?.code || '' }}</p>
        </div>
      </template>

      <!-- Type & Reason Column -->
      <template v-else-if="column.key === 'type_reason'">
        <div>
          <a-tag :color="getTypeColor(record.type)" class="mb-1">
            {{ record.type?.charAt(0).toUpperCase() + record.type?.slice(1) || 'Unknown' }}
          </a-tag>
          <p class="text-sm text-gray-600">
            {{ record.adjustment?.reason_display || record.reason }}
          </p>
        </div>
      </template>

      <!-- Status Column -->
      <template v-else-if="column.key === 'status'">
        <a-tag :color="getStatusColor(record.status)">
          {{ getStatusText(record.status) }}
        </a-tag>
        <div v-if="record.approved_at" class="mt-1">
          <p class="text-xs text-gray-500">
            {{ formatDate(record.approved_at) }}
          </p>
        </div>
      </template>

      <!-- Value Change Column -->
      <template v-else-if="column.key === 'value'">
        <div class="text-right">
          <p class="font-semibold" :class="{
            'text-green-600': record.value_change > 0,
            'text-red-600': record.value_change < 0,
            'text-gray-600': record.value_change === 0
          }">
            {{ record.value_change > 0 ? '+' : '' }}{{ formatCurrency(record.value_change) }}
          </p>
        </div>
      </template>

      <!-- Created By Column -->
      <template v-else-if="column.key === 'created_by'">
        <div>
          <p class="font-medium text-sm">{{ record.created_by?.name || 'Unknown' }}</p>
          <p v-if="record.approved_by" class="text-xs text-gray-500">
            Approved by: {{ record.approved_by.name }}
          </p>
        </div>
      </template>

      <!-- Date Column -->
      <template v-else-if="column.key === 'date'">
        <div class="text-center">
          <p class="font-medium text-sm">{{ formatDate(record.created_at) }}</p>
          <p class="text-xs text-gray-500">{{ formatDate(record.created_at).split(' ')[1] || '' }}</p>
        </div>
      </template>

      <!-- Actions Column -->
      <template v-else-if="column.key === 'actions'">
        <div class="flex justify-center space-x-1">
          <!-- View -->
          <IconTooltipButton
            tooltip="View Details"
            @click="viewAdjustment(record.adjustment)"
          >
            <EyeOutlined />
          </IconTooltipButton>

          <!-- Edit (only for draft) -->
          <IconTooltipButton
            v-if="record.status === 'draft'"
            tooltip="Edit Adjustment"
            @click="editAdjustment(record.adjustment)"
          >
            <EditOutlined />
          </IconTooltipButton>

          <!-- Submit for Approval (only for draft) -->
          <IconTooltipButton
            v-if="record.status === 'draft'"
            tooltip="Submit for Approval"
            @click="submitForApproval(record.adjustment)"
          >
            <SendOutlined />
          </IconTooltipButton>

          <!-- Approve (only for pending) -->
          <IconTooltipButton
            v-if="record.status === 'pending_approval'"
            tooltip="Approve Adjustment"
            @click="approveAdjustment(record.adjustment)"
            type="primary"
          >
            <CheckOutlined />
          </IconTooltipButton>

          <!-- Reject (only for pending) -->
          <IconTooltipButton
            v-if="record.status === 'pending_approval'"
            tooltip="Reject Adjustment"
            @click="rejectAdjustment(record.adjustment)"
            danger
          >
            <CloseOutlined />
          </IconTooltipButton>

          <!-- Delete (only for draft) -->
          <IconTooltipButton
            v-if="record.status === 'draft'"
            tooltip="Delete Adjustment"
            @click="deleteAdjustment(record.adjustment)"
            danger
          >
            <DeleteOutlined />
          </IconTooltipButton>
        </div>
      </template>
    </template>

    <!-- Empty State -->
    <template #emptyText>
      <div class="text-center py-8">
        <FileTextOutlined class="text-4xl text-gray-400 mb-4" />
        <p class="text-gray-500">No stock adjustments found</p>
        <p class="text-sm text-gray-400">Create your first adjustment to get started</p>
      </div>
    </template>
  </a-table>
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
