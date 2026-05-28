
<script setup>
import { ref, onMounted, computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { notification } from "ant-design-vue";
import { watchDebounced } from "@vueuse/core";
import {
  CrownOutlined,
  EyeOutlined,
  EditOutlined,
  ReloadOutlined,
} from "@ant-design/icons-vue";
import { IconEdit, IconTrash, IconEye } from "@tabler/icons-vue";
import axios from "axios";
import IconButtonTooltip from "@/Components/buttons/IconTooltip.vue";
import CustomerDetailsModal from "./CustomerDetailsModal.vue";
import PointsAdjustmentModal from "./PointsAdjustmentModal.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";

const isMdUp = useMediaQuery("(min-width: 768px)");

// Props - Remove customers and loading since we'll handle it internally
const props = defineProps({});

// Emits
const emit = defineEmits([]);

// State
const customers = ref([]);
const loading = ref(false);
const customerSearch = ref("");
const tierFilter = ref("");
const tierOptions = ref([]);
const showCustomerModal = ref(false);
const showPointsModal = ref(false);
const selectedCustomer = ref(null);
const adjustingPoints = ref(false);
const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

// Table columns
const customerColumns = [
  { title: "Customer", dataIndex: "name", key: "name", width: "25%" },
  {
    title: "Tier",
    dataIndex: "tier",
    key: "tier",
    width: "15%",
    align: "center",
  },
  {
    title: "Points",
    dataIndex: "loyalty_points",
    key: "points",
    width: "15%",
    align: "right",
  },
  {
    title: "Spending",
    dataIndex: "lifetime_spent",
    key: "spending",
    width: "20%",
    align: "right",
  },
  { title: "Actions", key: "actions", width: "15%", align: "center" },
];

// Detect if we're in a domain context
const isDomainContext = computed(() => {
  const match = window.location.pathname.match(/\/domains\/([^/]+)/);
  return match ? match[1] : null;
});

// Build API URLs based on context
const getApiUrl = (endpoint) => {
  if (isDomainContext.value) {
    return `/domains/${isDomainContext.value}/loyalty/${endpoint}`;
  }
  return `/api/${endpoint}`;
};

// Methods
const loadCustomers = async (page = 1) => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    if (customerSearch.value) params.append("search", customerSearch.value);
    if (tierFilter.value) params.append("tier", tierFilter.value);
    params.append("page", page);
    params.append("per_page", pagination.value.per_page);

    const response = await axios.get(`${getApiUrl("customers")}?${params}`);
    customers.value = response.data.data.map((customer) => {
      const tierInfo = customer.tier_info || getTierInfo(customer.tier);
      return {
        ...customer,
        tier_info: tierInfo,
      };
    });
    pagination.value = response.data.pagination || response.data.meta;
  } catch (error) {
    console.error("Failed to load customers:", error);
    notification.error({
      message: "Error",
      description: "Failed to load customers",
    });
  } finally {
    loading.value = false;
  }
};

const loadTierOptions = async () => {
  try {
    const response = await axios.get("/api/customers/tier-options");
    tierOptions.value = [{ value: "", label: "All Tiers" }, ...response.data];
  } catch (error) {
    console.error("Failed to load tier options:", error);
  }
};

const getTierInfo = (tier) => {
  const tierColors = {
    bronze: "#CD7F32",
    silver: "#C0C0C0",
    gold: "#FFD700",
    platinum: "#E5E4E2",
  };

  return {
    name: tier ? tier.charAt(0).toUpperCase() + tier.slice(1) : "Bronze",
    color: tierColors[tier] || tierColors.bronze,
  };
};

const viewCustomer = (customer) => {
  selectedCustomer.value = customer;
  showCustomerModal.value = true;
};

const adjustPoints = (customer) => {
  selectedCustomer.value = customer;
  showPointsModal.value = true;
};

const handlePointsAdjustment = async (adjustmentData) => {
  adjustingPoints.value = true;
  try {
    await axios.post(
      `${getApiUrl("customers")}/${selectedCustomer.value.id}/adjust-points`,
      adjustmentData
    );

    notification.success({
      message: "Points Adjusted",
      description: `${selectedCustomer.value.name}'s points have been ${
        adjustmentData.type === "add" ? "added" : "deducted"
      }`,
    });

    showPointsModal.value = false;
    loadCustomers(pagination.value.current_page);
  } catch (error) {
    notification.error({
      message: "Adjustment Failed",
      description: error.response?.data?.message || "Failed to adjust points",
    });
  } finally {
    adjustingPoints.value = false;
  }
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

const handleTableChange = (paginationInfo) => {
  loadCustomers(paginationInfo.current);
};

function onMobilePaginationChange(pageNum) {
  loadCustomers(pageNum);
}

const tablePagination = computed(() => ({
  current: pagination.value.current_page,
  total: pagination.value.total,
  pageSize: pagination.value.per_page,
  showSizeChanger: true,
  showQuickJumper: true,
  showTotal: (total, range) =>
    `${range[0]}-${range[1]} of ${total} customers`,
}));

// Watch for search and filter changes with debounce
watchDebounced(
  [customerSearch, tierFilter],
  () => {
    loadCustomers(1); // Reset to first page when searching
  },
  { debounce: 300 }
);

// Load data on mount
onMounted(() => {
  loadTierOptions();
  loadCustomers();
});
</script>

<template>
  <div class="p-4 md:p-6">
    <!-- Search and Filters -->
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div class="overflow-x-auto">
        <a-radio-group v-model:value="tierFilter" button-style="solid">
          <a-radio-button value="">All</a-radio-button>
          <a-radio-button
            v-for="tier in tierOptions.filter((t) => t.value !== '')"
            :key="tier.value"
            :value="tier.value"
          >
            {{ tier.label }}
          </a-radio-button>
        </a-radio-group>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <a-input
          v-model:value="customerSearch"
          placeholder="Search customers by name, email, or phone..."
          class="w-full md:w-[400px]"
          allow-clear
        />

        <refresh-button
          :loading="loading"
          @click="() => loadCustomers(pagination.current_page)"
        />
      </div>
    </div>

    <!-- Customers Table -->
    <a-table
      v-if="isMdUp"
      :columns="customerColumns"
      :data-source="customers"
      :loading="loading"
      row-key="id"
      :pagination="tablePagination"
      @change="handleTableChange"
      bordered
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div>
            <div class="font-medium">{{ record.name }}</div>
            <div class="text-sm text-gray-500">{{ record.email }}</div>
            <div class="text-xs text-gray-400">{{ record.phone }}</div>
          </div>
        </template>

        <template v-if="column.key === 'tier'">
          <a-tag class="w-fit" :color="getTierColor(record.tier)">
            <crown-outlined class="mr-1" />
            {{
              record.tier
                ? record.tier.charAt(0).toUpperCase() + record.tier.slice(1)
                : "Bronze"
            }}
          </a-tag>
        </template>

        <template v-if="column.key === 'points'">
          <div class="text-right">
            <div class="font-medium">
              {{ record.loyalty_points?.toLocaleString() || 0 }}
            </div>
            <div class="text-xs text-gray-500">points</div>
          </div>
        </template>

        <template v-if="column.key === 'spending'">
          <div class="text-right">
            <div class="font-medium">
              ₱{{ record.lifetime_spent?.toLocaleString() || 0 }}
            </div>
            <div class="text-xs text-gray-500">lifetime</div>
          </div>
        </template>

        <template v-if="column.key === 'actions'">
          <a-space>
            <IconButtonTooltip
              name="View Details"
              hover="hover:bg-blue-500"
              @click="viewCustomer(record)"
            >
              <IconEye size="20" class="mx-auto" />
            </IconButtonTooltip>
            <IconButtonTooltip
              name="Adjust Points"
              hover="hover:bg-green-500"
              @click="adjustPoints(record)"
            >
              <IconEdit size="20" class="mx-auto" />
            </IconButtonTooltip>
          </a-space>
        </template>
      </template>
    </a-table>

    <div v-else>
      <a-spin :spinning="loading">
        <div
          v-if="!customers.length"
          class="py-12 text-center text-sm text-gray-500"
        >
          No customers found
        </div>
        <div v-else class="flex flex-col gap-3">
          <div
            v-for="record in customers"
            :key="record.id"
            class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
          >
            <div class="px-4 py-3">
              <div class="font-medium text-gray-900">{{ record.name }}</div>
              <div class="text-sm text-gray-500">{{ record.email }}</div>
              <div v-if="record.phone" class="text-xs text-gray-400">
                {{ record.phone }}
              </div>
              <div class="mt-2">
                <a-tag class="m-0 w-fit" :color="getTierColor(record.tier)">
                  <crown-outlined class="mr-1" />
                  {{
                    record.tier
                      ? record.tier.charAt(0).toUpperCase() +
                        record.tier.slice(1)
                      : "Bronze"
                  }}
                </a-tag>
              </div>
            </div>

            <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
              <div
                class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
              >
                <span class="text-gray-500">Points</span>
                <span class="text-right font-semibold text-gray-900">
                  {{ record.loyalty_points?.toLocaleString() || 0 }}
                </span>
                <span class="text-gray-500">Spending</span>
                <span class="text-right font-semibold text-green-600">
                  ₱{{ record.lifetime_spent?.toLocaleString() || 0 }}
                </span>
              </div>
            </div>

            <div class="border-t border-gray-100 px-4 py-3">
              <div class="flex flex-col gap-2">
                <a-button
                  class="flex items-center justify-center gap-2"
                  @click="viewCustomer(record)"
                >
                  <template #icon>
                    <IconEye size="18" />
                  </template>
                  View details
                </a-button>
                <a-button
                  class="flex items-center justify-center gap-2"
                  @click="adjustPoints(record)"
                >
                  <template #icon>
                    <IconEdit size="18" />
                  </template>
                  Adjust points
                </a-button>
              </div>
            </div>
          </div>
        </div>
        <a-pagination
          v-if="
            pagination.total &&
            pagination.total > pagination.per_page
          "
          class="mt-4 justify-center pt-2"
          show-less-items
          :current="pagination.current_page"
          :page-size="pagination.per_page"
          :total="pagination.total"
          :show-size-changer="false"
          @change="onMobilePaginationChange"
        />
      </a-spin>
    </div>

    <!-- Customer Details Modal -->
    <CustomerDetailsModal
      :visible="showCustomerModal"
      :customer="selectedCustomer"
      @close="showCustomerModal = false"
    />

    <!-- Points Adjustment Modal -->
    <PointsAdjustmentModal
      :visible="showPointsModal"
      :customer="selectedCustomer"
      :adjusting="adjustingPoints"
      @close="showPointsModal = false"
      @save="handlePointsAdjustment"
    />
  </div>
</template>

