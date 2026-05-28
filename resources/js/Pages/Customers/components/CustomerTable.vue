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

  <div v-else class="px-2 py-2 md:px-0">
    <a-spin :spinning="loading">
      <div
        v-if="!customers?.length"
        class="py-12 text-center text-sm text-gray-500"
      >
        No customers found.
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
              <div class="mt-1 flex flex-wrap gap-1">
                <a-tag
                  v-if="record.loyalty_points !== null"
                  class="m-0 capitalize"
                  :color="getTierTagColor(record.tier)"
                >
                  {{ record.tier || 'Bronze' }}
                </a-tag>
                <a-tag
                  v-if="record.loyalty_points !== null"
                  class="m-0"
                  color="blue"
                >
                  {{ record.loyalty_points?.toLocaleString() || 0 }} pts
                </a-tag>
                <a-tag v-else class="m-0" color="default">
                  Not enrolled
                </a-tag>
              </div>
            </div>
          </div>

          <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
            <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
              <span class="text-gray-500">Email</span>
              <span class="truncate text-right font-medium text-gray-900">
                {{ record.email || 'N/A' }}
              </span>
              <span class="text-gray-500">Phone</span>
              <span class="text-right font-medium text-gray-900">
                {{ record.phone || 'N/A' }}
              </span>
              <template v-if="showSuperUserDomain">
                <span class="text-gray-500">Domain</span>
                <span
                  class="flex min-w-0 items-center justify-end gap-1 truncate font-medium text-gray-900"
                >
                  <IconWorld size="16" class="shrink-0" />
                  {{ record.domain || 'N/A' }}
                </span>
              </template>
            </div>
          </div>

          <div class="border-t border-gray-100 px-4 py-3">
            <div class="grid grid-cols-2 gap-2">
              <a-button
                class="flex items-center justify-center gap-2"
                @click="$emit('view', record)"
              >
                <template #icon>
                  <IconEye size="18" />
                </template>
                View
              </a-button>
              <a-button
                class="flex items-center justify-center gap-2"
                @click="$emit('edit', record)"
              >
                <template #icon>
                  <IconEdit size="18" />
                </template>
                Edit
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
import { usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import { IconEye, IconEdit, IconWorld } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";

const page = usePage();
const isMdUp = useMediaQuery("(min-width: 768px)");

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

const emit = defineEmits(['change', 'edit', 'view']);

const showSuperUserDomain = computed(
  () => page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

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

  if (showSuperUserDomain.value) {
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

const handleChange = (pagination, filters, sorter) => {
  emit('change', pagination, filters, sorter);
};

function onMobilePaginationChange(pageNum) {
  emit('change', {
    current: pageNum,
    pageSize: props.pagination?.pageSize ?? 10,
  });
}

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

const getTierTagColor = (tier) => {
  const tagColors = {
    bronze: "orange",
    silver: "default",
    gold: "gold",
    platinum: "purple",
  };
  return tagColors[tier] || "orange";
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
