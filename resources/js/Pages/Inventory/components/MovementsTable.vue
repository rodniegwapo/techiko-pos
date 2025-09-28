<script setup>
import { computed } from "vue";
import { 
  ArrowUpOutlined, 
  ArrowDownOutlined,
  HistoryOutlined,
  UserOutlined,
  EnvironmentOutlined
} from "@ant-design/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";

const { formatCurrency, formatDate, formatDateTime } = useHelpers();

defineEmits(["handleTableChange"]);

const props = defineProps({
  movements: {
    type: Object,
    default: () => ({}),
  },
  pagination: {
    type: Object,
    default: () => ({}),
  },
});

const columns = [
  { title: "Date & Time", dataIndex: "date", key: "date", align: "left", width: "15%" },
  { title: "Product", dataIndex: "product", key: "product", align: "left", width: "20%" },
  { title: "Location", dataIndex: "location", key: "location", align: "left", width: "12%" },
  { title: "Movement Type", dataIndex: "type", key: "type", align: "left", width: "12%" },
  { title: "Quantity Change", dataIndex: "quantity", key: "quantity", align: "center", width: "12%" },
  { title: "Before/After", dataIndex: "before_after", key: "before_after", align: "center", width: "12%" },
  { title: "Cost", dataIndex: "cost", key: "cost", align: "right", width: "10%" },
  { title: "User", dataIndex: "user", key: "user", align: "left", width: "10%" },
];

const getMovementTypeColor = (type) => {
  const colors = {
    sale: 'red',
    purchase: 'green',
    adjustment: 'blue',
    transfer_in: 'cyan',
    transfer_out: 'orange',
    return: 'purple',
    damage: 'red',
    theft: 'red',
    expired: 'volcano',
    promotion: 'gold',
  };
  return colors[type] || 'default';
};

const getMovementTypeIcon = (type) => {
  const icons = {
    sale: ArrowDownOutlined,
    purchase: ArrowUpOutlined,
    adjustment: HistoryOutlined,
    transfer_in: ArrowUpOutlined,
    transfer_out: ArrowDownOutlined,
    return: ArrowUpOutlined,
    damage: ArrowDownOutlined,
    theft: ArrowDownOutlined,
    expired: ArrowDownOutlined,
    promotion: ArrowDownOutlined,
  };
  return icons[type] || HistoryOutlined;
};

const getQuantityChangeColor = (change) => {
  if (change > 0) return 'text-green-600';
  if (change < 0) return 'text-red-600';
  return 'text-gray-600';
};

const dataSource = computed(() => {
  return props.movements?.data?.map(movement => ({
    key: movement.id,
    id: movement.id,
    date: movement.created_at,
    product: movement.product,
    location: movement.location,
    type: movement.movement_type,
    quantity_change: movement.quantity_change,
    quantity_before: movement.quantity_before,
    quantity_after: movement.quantity_after,
    unit_cost: movement.unit_cost,
    total_cost: movement.total_cost,
    user: movement.user,
    notes: movement.notes,
    reason: movement.reason,
    batch_number: movement.batch_number,
    expiry_date: movement.expiry_date,
    movement: movement,
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
    <!-- Date Column -->
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">
        <div>
          <p class="font-medium text-sm">{{ formatDate(record.date) }}</p>
          <p class="text-xs text-gray-500">{{ formatDateTime(record.date).split(' ')[1] }}</p>
        </div>
      </template>

      <!-- Product Column -->
      <template v-else-if="column.key === 'product'">
        <div>
          <p class="font-medium text-sm">{{ record.product?.name || 'Unknown Product' }}</p>
          <p class="text-xs text-gray-500">SKU: {{ record.product?.SKU || 'N/A' }}</p>
          <p v-if="record.batch_number" class="text-xs text-blue-600">
            Batch: {{ record.batch_number }}
          </p>
        </div>
      </template>

      <!-- Location Column -->
      <template v-else-if="column.key === 'location'">
        <div class="flex items-center">
          <EnvironmentOutlined class="text-gray-400 mr-1" />
          <div>
            <p class="font-medium text-sm">{{ record.location?.name || 'Unknown' }}</p>
            <p class="text-xs text-gray-500">{{ record.location?.code || '' }}</p>
          </div>
        </div>
      </template>

      <!-- Movement Type Column -->
      <template v-else-if="column.key === 'type'">
        <a-tag :color="getMovementTypeColor(record.type)">
          <component :is="getMovementTypeIcon(record.type)" class="mr-1" />
          {{ record.movement?.movement_type_display || record.type }}
        </a-tag>
        <div v-if="record.reason" class="mt-1">
          <p class="text-xs text-gray-500">{{ record.reason }}</p>
        </div>
      </template>

      <!-- Quantity Change Column -->
      <template v-else-if="column.key === 'quantity'">
        <div class="text-center">
          <p class="font-bold text-lg" :class="getQuantityChangeColor(record.quantity_change)">
            {{ record.quantity_change > 0 ? '+' : '' }}{{ record.quantity_change }}
          </p>
          <p class="text-xs text-gray-500">
            {{ record.product?.unit_of_measure || 'pcs' }}
          </p>
        </div>
      </template>

      <!-- Before/After Column -->
      <template v-else-if="column.key === 'before_after'">
        <div class="text-center">
          <div class="text-xs space-y-1">
            <div class="flex justify-between">
              <span class="text-gray-500">Before:</span>
              <span class="font-medium">{{ record.quantity_before }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">After:</span>
              <span class="font-medium">{{ record.quantity_after }}</span>
            </div>
          </div>
        </div>
      </template>

      <!-- Cost Column -->
      <template v-else-if="column.key === 'cost'">
        <div class="text-right">
          <p v-if="record.unit_cost" class="font-medium text-sm">
            {{ formatCurrency(record.unit_cost) }}
          </p>
          <p v-if="record.total_cost" class="text-xs text-gray-500">
            Total: {{ formatCurrency(record.total_cost) }}
          </p>
          <p v-if="!record.unit_cost && !record.total_cost" class="text-xs text-gray-400">
            N/A
          </p>
        </div>
      </template>

      <!-- User Column -->
      <template v-else-if="column.key === 'user'">
        <div class="flex items-center">
          <UserOutlined class="text-gray-400 mr-1" />
          <div>
            <p class="font-medium text-sm">{{ record.user?.name || 'System' }}</p>
          </div>
        </div>
      </template>
    </template>

    <!-- Expandable Row for Additional Details -->
    <template #expandedRowRender="{ record }">
      <div class="bg-gray-50 p-4 rounded-lg">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <!-- Notes -->
          <div v-if="record.notes">
            <h4 class="font-medium text-gray-700 mb-1">Notes</h4>
            <p class="text-sm text-gray-600">{{ record.notes }}</p>
          </div>

          <!-- Batch Information -->
          <div v-if="record.batch_number || record.expiry_date">
            <h4 class="font-medium text-gray-700 mb-1">Batch Information</h4>
            <div class="text-sm text-gray-600 space-y-1">
              <p v-if="record.batch_number">
                <span class="font-medium">Batch:</span> {{ record.batch_number }}
              </p>
              <p v-if="record.expiry_date">
                <span class="font-medium">Expires:</span> {{ formatDate(record.expiry_date) }}
              </p>
            </div>
          </div>

          <!-- Reference Information -->
          <div v-if="record.movement?.reference_type">
            <h4 class="font-medium text-gray-700 mb-1">Reference</h4>
            <div class="text-sm text-gray-600 space-y-1">
              <p>
                <span class="font-medium">Type:</span> {{ record.movement.reference_type }}
              </p>
              <p v-if="record.movement.reference_id">
                <span class="font-medium">ID:</span> {{ record.movement.reference_id }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Empty State -->
    <template #emptyText>
      <div class="text-center py-8">
        <HistoryOutlined class="text-4xl text-gray-400 mb-4" />
        <p class="text-gray-500">No inventory movements found</p>
        <p class="text-sm text-gray-400">Try adjusting your filters or check back later</p>
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

.ant-table-row-expand-icon {
  color: #1890ff;
}
</style>
