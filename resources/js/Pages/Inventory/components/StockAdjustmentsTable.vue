<script setup>
import { computed } from "vue";
import { 
  IconEye,
  IconEdit,
  IconCheck,
  IconX,
  IconSend,
  IconTrash,
  IconFileText
} from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { router } from "@inertiajs/vue3";

const { formatCurrency, formatDate, confirmDelete, showNotification } = useHelpers();

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
});

// Simplified columns - only essential information
const columns = [
  { title: "Adjustment #", dataIndex: "number", key: "number", align: "left", width: "15%" },
  { title: "Location", dataIndex: "location", key: "location", align: "center", width: "15%" },
  { title: "Reason", dataIndex: "reason", key: "reason", align: "left", width: "20%" },
  { title: "Status", dataIndex: "status", key: "status", align: "center", width: "15%" },
  { title: "Items", dataIndex: "items", key: "items", align: "center", width: "10%" },
  { title: "Value", dataIndex: "value", key: "value", align: "right", width: "15%" },
  { title: "Actions", key: "actions", align: "center", width: "10%" },
];

const getStatusColor = (status) => {
  const colors = {
    draft: 'blue',
    pending_approval: 'orange',
    approved: 'green',
    rejected: 'red',
    completed: 'purple',
  };
  return colors[status] || 'default';
};

const showDetails = (adjustment) => {
  emit('showDetails', adjustment);
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
        <div class="text-center">
          <p class="font-medium text-sm">{{ record.location?.name || 'Unknown' }}</p>
        </div>
      </template>

      <!-- Reason Column -->
      <template v-else-if="column.key === 'reason'">
        <div>
          <p class="text-sm text-gray-900">{{ record.reason || 'No reason provided' }}</p>
        </div>
      </template>

      <!-- Status Column -->
      <template v-else-if="column.key === 'status'">
        <div class="text-center">
          <a-tag :color="getStatusColor(record.status)">
            {{ getStatusText(record.status) }}
          </a-tag>
        </div>
      </template>

      <!-- Items Column -->
      <template v-else-if="column.key === 'items'">
        <div class="text-center">
          <p class="font-semibold">{{ record.adjustment?.items?.length || 0 }}</p>
          <p class="text-xs text-gray-500">items</p>
        </div>
      </template>

      <!-- Value Column -->
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


      <!-- Actions Column -->
      <template v-else-if="column.key === 'actions'">
        <div class="flex justify-center">
          <IconTooltipButton
            name="View Details"
            @click="showDetails(record.adjustment)"
          >
            <IconEye :size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>

    <!-- Empty State -->
    <template #emptyText>
      <div class="text-center py-8">
        <IconFileText :size="48" class="mx-auto text-gray-400 mb-4" />
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
