<script setup>
import { computed } from "vue";
import {
  IconCircleCheck,
  IconAlertTriangle,
  IconCircleX,
  IconEye,
  IconArrowsExchange,
} from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useHelpers } from "@/Composables/useHelpers";
import { router } from "@inertiajs/vue3";

const { formatCurrency, formatDate } = useHelpers();

const emit = defineEmits(["handleTableChange", "showDetails", "transferStock"]);

const props = defineProps({
  inventories: {
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
  { title: "Product", dataIndex: "product", key: "product", align: "left" },
  { title: "Stock", dataIndex: "stock", key: "stock", align: "left" },
  { title: "Status", dataIndex: "status", key: "status", align: "left" },
  { title: "Value", dataIndex: "value", key: "value", align: "left" },
  { title: "Actions", key: "actions", align: "center", width: "1%" },
];

const getStockStatusColor = (status) => {
  switch (status) {
    case "in_stock":
      return "success";
    case "low_stock":
      return "warning";
    case "out_of_stock":
      return "error";
    default:
      return "default";
  }
};

const getStockStatusIcon = (status) => {
  switch (status) {
    case "in_stock":
      return IconCircleCheck;
    case "low_stock":
      return IconAlertTriangle;
    case "out_of_stock":
      return IconCircleX;
    default:
      return IconCircleCheck;
  }
};

const getStockStatusText = (status) => {
  switch (status) {
    case "in_stock":
      return "In Stock";
    case "low_stock":
      return "Low Stock";
    case "out_of_stock":
      return "Out of Stock";
    default:
      return "Unknown";
  }
};

const showDetails = (inventory) => {
  emit("showDetails", inventory);
};

const transferStock = (inventory) => {
  emit("transferStock", inventory);
};

const dataSource = computed(() => {
  return (
    props.inventories?.data?.map((inventory) => ({
      key: inventory.id,
      id: inventory.id,
      product: inventory.product,
      sku: inventory.product?.SKU || "N/A",
      stock: inventory.quantity_on_hand,
      available: inventory.quantity_available,
      reserved: inventory.quantity_reserved,
      status: inventory.product?.stock_status || "unknown",
      value: inventory.total_value,
      last_movement: inventory.last_movement_at,
      inventory: inventory,
    })) || []
  );
});
</script>

<template>
  <a-table
    :columns="columns"
    :data-source="dataSource"
    :pagination="pagination"
    :loading="false"
    @change="$emit('handleTableChange', $event)"

  >
    <!-- Product Column -->
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'product'">
        <div class="flex items-center space-x-3">
          <!-- Product Image/Avatar -->
          <div
            class="w-10 h-10 bg-gray-200 rounded-lg flex items-center justify-center"
          >
            <img
              v-if="
                record.product?.representation_type === 'image' &&
                record.product?.representation
              "
              :src="record.product.representation"
              :alt="record.product.name"
              class="w-full h-full object-cover rounded-lg"
            />
            <div
              v-else-if="
                record.product?.representation_type === 'color' &&
                record.product?.representation
              "
              class="w-full h-full rounded-lg"
              :style="{ backgroundColor: `#${record.product.representation}` }"
            ></div>
            <span v-else class="text-xs text-gray-500">
              {{ record.product?.name?.charAt(0) || "P" }}
            </span>
          </div>

          <!-- Product Info -->
          <div>
            <p class="font-semibold text-gray-900">
              {{ record.product?.name || "Unknown Product" }}
            </p>
            <p class="text-sm text-gray-500">
              {{ record.product?.category?.name || "No Category" }}
            </p>
          </div>
        </div>
      </template>

      <!-- Stock Column -->
      <template v-else-if="column.key === 'stock'">
        <div class="text-center">
          <p class="font-semibold text-lg">{{ Math.floor(record.stock) }}</p>
          <p class="text-xs text-gray-500">
            {{ record.product?.unit_of_measure || "pcs" }}
          </p>
        </div>
      </template>

      <!-- Status Column -->
      <template v-else-if="column.key === 'status'">
        <a-tag class="w-fit" :color="getStockStatusColor(record.status)">
          <component
            :is="getStockStatusIcon(record.status)"
            :size="16"
            class="mr-1"
          />
          {{ getStockStatusText(record.status) }}
        </a-tag>
      </template>

      <!-- Value Column -->
      <template v-else-if="column.key === 'value'">
        <div>
          <p class="font-semibold">{{ formatCurrency(record.value) }}</p>
          <p class="text-xs text-gray-500">
            @ {{ formatCurrency(record.inventory?.average_cost || 0) }}
          </p>
        </div>
      </template>

      <!-- Actions Column -->
      <template v-else-if="column.key === 'actions'">
        <div class="flex justify-center space-x-1">
          <IconTooltipButton
            name="View Details"
            @click="showDetails(record.inventory)"
          >
            <IconEye :size="20" class="mx-auto" />
          </IconTooltipButton>

          <IconTooltipButton
            name="Transfer Stock"
            @click="transferStock(record.inventory)"
          >
            <IconArrowsExchange :size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>

    <!-- Empty State -->
    <template #emptyText>
      <div class="text-center py-8">
        <IconCircleX :size="48" class="mx-auto text-gray-400 mb-4" />
        <p class="text-gray-500">No inventory records found</p>
        <p class="text-sm text-gray-400">
          Try adjusting your filters or add some inventory
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
</style>
