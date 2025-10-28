<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { IconArrowUp, IconArrowDown, IconEye, IconWorld } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useHelpers } from "@/Composables/useHelpers";

const { formatCurrency, formatDate, formatDateTime } = useHelpers();
const page = usePage();

const emit = defineEmits(["handleTableChange", "showDetails"]);

const props = defineProps({
  movements: {
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

// Simplified columns - only essential information
const columns = computed(() => {
  const baseColumns = [
    {
      title: "Date & Time",
      dataIndex: "date",
      key: "date",
      align: "left",
    },
    {
      title: "Product",
      dataIndex: "product",
      key: "product",
      align: "left",
    },
    {
      title: "Type",
      dataIndex: "type",
      key: "type",
      align: "left",
    },
    {
      title: "Quantity",
      dataIndex: "quantity",
      key: "quantity",
      align: "left",
    },
  ];

  // Add domain column for super users only in global view
  if (page.props.auth?.user?.data?.is_super_user && props.isGlobalView) {
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

const getMovementTypeColor = (type) => {
  const colors = {
    sale: "red",
    purchase: "green",
    adjustment: "blue",
    transfer_in: "cyan",
    transfer_out: "orange",
    return: "purple",
    damage: "red",
    theft: "red",
    expired: "volcano",
    promotion: "gold",
  };
  return colors[type] || "default";
};

const getMovementTypeIcon = (type) => {
  const increaseTypes = ["purchase", "adjustment", "transfer_in", "return"];
  return increaseTypes.includes(type) ? IconArrowUp : IconArrowDown;
};

const showDetails = (movement) => {
  emit("showDetails", movement);
};

const getQuantityChangeColor = (change) => {
  if (change > 0) return "text-green-600";
  if (change < 0) return "text-red-600";
  return "text-gray-600";
};

const dataSource = computed(() => {
  return (
    props.movements?.data?.map((movement) => ({
      key: movement.id,
      id: movement.id,
      date: movement.created_at,
      product: movement.product,
      location: movement.location,
      type: movement.movement_type,
      domain: movement.domain,
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
    })) || []
  );
});
</script>

<template>
  <a-table
    :columns="columns"
    :data-source="dataSource"
    :pagination="pagination"
    :loading="loading"
    @change="$emit('handleTableChange', $event)"
  >
    <!-- Date Column -->
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">
        <div>
          <p class="font-medium text-sm">{{ formatDate(record.date) }}</p>
        </div>
      </template>

      <!-- Product Column -->
      <template v-else-if="column.key === 'product'">
        <div>
          <p class="font-medium text-sm">
            {{ record.product?.name || "Unknown Product" }}
          </p>
          <p class="text-xs text-gray-500">
            SKU: {{ record.product?.SKU || "N/A" }}
          </p>
          <p v-if="record.batch_number" class="text-xs text-blue-600">
            Batch: {{ record.batch_number }}
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


      <!-- Movement Type Column -->
      <template v-else-if="column.key === 'type'">
        <div class="text-center">
          <a-tag class="w-fit" :color="getMovementTypeColor(record.type)">
            <component
              :is="getMovementTypeIcon(record.type)"
              :size="14"
              class="mr-1"
            />
            {{ record.movement?.movement_type_display || record.type }}
          </a-tag>
        </div>
      </template>

      <!-- Quantity Change Column -->
      <template v-else-if="column.key === 'quantity'">
        <div class="text-center flex items-center gap-2">
          <span
            class="font-bold text-lg"
            :class="getQuantityChangeColor(record.quantity_change)"
          >
            {{ record.quantity_change > 0 ? "+" : ""
            }}{{ record.quantity_change }}
          </span>
          <span class="text-xs text-gray-500">
            {{ record.product?.unit_of_measure || "pcs" }}
          </span>
        </div>
      </template>

      <!-- Actions Column -->
      <template v-else-if="column.key === 'actions'">
        <div class="flex justify-center">
          <IconTooltipButton
            name="View Details"
            @click="showDetails(record.movement)"
          >
            <IconEye :size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>

    <!-- Empty State -->
    <template #emptyText>
      <div class="text-center py-8">
        <IconArrowDown :size="48" class="mx-auto text-gray-400 mb-4" />
        <p class="text-gray-500">No inventory movements found</p>
        <p class="text-sm text-gray-400">
          Try adjusting your filters or check back later
        </p>
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
