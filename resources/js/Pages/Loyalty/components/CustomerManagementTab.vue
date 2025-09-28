
<script setup>
import { ref, onMounted } from "vue";
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

// Methods
const loadCustomers = async (page = 1) => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    if (customerSearch.value) params.append("search", customerSearch.value);
    if (tierFilter.value) params.append("tier", tierFilter.value);
    params.append("page", page);
    params.append("per_page", pagination.value.per_page);

    const response = await axios.get(`/api/customers?${params}`);
    customers.value = response.data.data.map((customer) => {
      const tierInfo = customer.tier_info || getTierInfo(customer.tier);
      return {
        ...customer,
        tier_info: tierInfo,
      };
    });
    pagination.value = response.data.meta;
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
      `/api/loyalty/customers/${selectedCustomer.value.id}/adjust-points`,
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
  <div class="p-6">
    <!-- Search and Filters -->
    <div class="flex justify-between mb-4 gap-2">
      <div class="flex items-center gap-2">
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
      <div class="flex items-center gap-2">
        <div>
          <a-input
            v-model:value="customerSearch"
            placeholder="Search customers by name, email, or phone..."
            class="w-[400px]"
            allow-clear
          />
        </div>

        <refresh-button
          :loading="loading"
          @click="() => loadCustomers(pagination.current_page)"
        />
      </div>
    </div>

    <!-- Customers Table -->
    <a-table
      :columns="customerColumns"
      :data-source="customers"
      :loading="loading"
      row-key="id"
      :pagination="{
        current: pagination.current_page,
        total: pagination.total,
        pageSize: pagination.per_page,
        showSizeChanger: true,
        showQuickJumper: true,
        showTotal: (total, range) =>
          `${range[0]}-${range[1]} of ${total} customers`,
      }"
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

