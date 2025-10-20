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
            <div class="text-sm text-gray-500">{{ record.email }}</div>
          </div>
        </div>
      </template>

      <template v-if="column.key === 'domain'">
        <div class="flex items-center justify-center">
          <IconWorld class="mr-1" size="16" />
          <span class="text-sm font-medium">{{ record.domain || 'N/A' }}</span>
        </div>
      </template>

      <template v-if="column.key === 'contact'">
        <div>
          <div class="font-medium">{{ record.phone || 'N/A' }}</div>
          <div class="text-sm text-gray-500">{{ record.address || 'No address' }}</div>
        </div>
      </template>

      <template v-if="column.key === 'loyalty_info'">
        <div v-if="record.loyalty_points !== null">
          <div class="flex items-center mb-1">
            <div
              class="w-3 h-3 rounded-full mr-2"
              :style="{ backgroundColor: getTierColor(record.tier) }"
            ></div>
            <span class="font-medium capitalize">{{ record.tier || 'Bronze' }}</span>
          </div>
          <div class="text-sm text-blue-600 font-medium">
            {{ record.loyalty_points?.toLocaleString() || 0 }} points
          </div>
        </div>
        <div v-else class="text-gray-400 text-sm">
          Not enrolled
        </div>
      </template>

      <template v-if="column.key === 'stats'">
        <div class="text-center">
          <div class="font-medium text-lg">{{ record.total_purchases || 0 }}</div>
          <div class="text-sm text-gray-500">purchases</div>
          <div class="text-sm font-medium text-green-600 mt-1">
            ₱{{ (record.lifetime_spent || 0).toLocaleString() }}
          </div>
        </div>
      </template>

      <template v-if="column.key === 'created_at'">
        <div class="text-sm">
          {{ formatDate(record.created_at) }}
        </div>
      </template>

      <template v-if="column.key === 'actions'">
        <div class="flex items-center gap-2">
          <IconTooltipButton
            hover="group-hover:bg-blue-500"
            name="View Details"
            @click="$emit('view', record)"
          >
            <IconEye size="20" class="mx-auto" />
          </IconTooltipButton>

          <IconTooltipButton
            hover="group-hover:bg-green-500"
            name="Edit Customer"
            @click="$emit('edit', record)"
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
import { IconEye, IconEdit, IconWorld } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";

// Props
const props = defineProps({
  customers: {
    type: Array,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  pagination: {
    type: Object,
    default: () => ({}),
  },
  isGlobalView: {
    type: Boolean,
    default: false,
  },
});

// Emits
const emit = defineEmits(['change', 'edit', 'view']);

// Table columns
const columns = computed(() => {
  const baseColumns = [
    {
      title: "Customer",
      dataIndex: "name",
      key: "name",
      width: "25%",
    },
    {
      title: "Contact Info",
      key: "contact",
      width: "20%",
    },
    {
      title: "Loyalty Status",
      key: "loyalty_info",
      align: "center",
      width: "15%",
    },
    {
      title: "Purchase Stats",
      key: "stats",
      align: "center",
      width: "15%",
    },
    {
      title: "Member Since",
      dataIndex: "created_at",
      key: "created_at",
      align: "center",
      width: "15%",
    },
  ];

  // Add domain column for global view
  if (props.isGlobalView) {
    baseColumns.splice(2, 0, {
      title: "Domain",
      dataIndex: "domain",
      key: "domain",
      align: "center",
      width: "15%",
    });
  }

  baseColumns.push({
    title: "Actions",
    key: "actions",
    align: "center",
    width: "10%",
  });

  return baseColumns;
});

// Methods
const handleChange = (pagination, filters, sorter) => {
  emit('change', pagination, filters, sorter);
};

const getInitials = (name) => {
  if (!name) return "?";
  return name
    .split(" ")
    .map((word) => word.charAt(0))
    .join("")
    .toUpperCase()
    .slice(0, 2);
};

const getAvatarColor = (name) => {
  const colors = [
    "#f56565", "#ed8936", "#ecc94b", "#48bb78", "#38b2ac",
    "#4299e1", "#667eea", "#9f7aea", "#ed64a6", "#a0aec0"
  ];
  if (!name) return colors[0];
  const index = name.charCodeAt(0) % colors.length;
  return colors[index];
};

const getTierColor = (tier) => {
  const tierColors = {
    bronze: "#CD7F32",
    silver: "#C0C0C0",
    gold: "#FFD700",
    platinum: "#E5E4E2",
  };
  return tierColors[tier] || tierColors.bronze;
};

const formatDate = (date) => {
  if (!date) return "N/A";
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};
</script>
