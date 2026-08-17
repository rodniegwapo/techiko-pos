<script setup>
import { ref, onMounted, computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import { notification, Modal } from "ant-design-vue";
import { watchDebounced } from "@vueuse/core";
import {
  PlusOutlined,
  EditOutlined,
  DeleteOutlined,
  PlusSquareOutlined,
} from "@ant-design/icons-vue";
import axios from "axios";
import IconTooltip from "@/Components/buttons/IconTooltip.vue";
import TierFormModal from "./TierFormModal.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import { IconEdit, IconTrash } from "@tabler/icons-vue";

const isMdUp = useMediaQuery("(min-width: 768px)");

// State
const tiers = ref([]);
const loading = ref(false);
const tierSearch = ref("");
const statusFilter = ref("");
const showAddTierModal = ref(false);
const showEditTierModal = ref(false);
const editingTier = ref(null);
const savingTier = ref(false);

// Pagination
const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

// Table columns
const tierColumns = [
  { title: "Tier Name", dataIndex: "display_name", key: "display_name" },
  {
    title: "Multiplier",
    dataIndex: "multiplier",
    key: "multiplier",
    align: "center",
  },
  {
    title: "Threshold",
    dataIndex: "spending_threshold",
    key: "spending_threshold",
    align: "right",
  },
  { title: "Color", dataIndex: "color", key: "color", align: "center" },
  {
    title: "Active",
    dataIndex: "is_active",
    key: "is_active",
    align: "center",
  },
  { title: "Actions", key: "actions", align: "center", width: "120px" },
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
  return `/api/loyalty/${endpoint}`;
};

// Load tiers with search and filter
const loadTiers = async (page = 1) => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    if (tierSearch.value) params.append("search", tierSearch.value);
    if (statusFilter.value) params.append("status", statusFilter.value);
    params.append("page", page);
    params.append("per_page", pagination.value.per_page);

    const response = await axios.get(`${getApiUrl("tiers")}?${params}`);
    tiers.value = response.data.data;
    pagination.value = response.data.meta;
  } catch (error) {
    console.error("Failed to load tiers:", error);
    notification.error({
      message: "Error",
      description: "Failed to load tiers",
    });
  } finally {
    loading.value = false;
  }
};

// Handle table pagination/sorting changes
const handleTableChange = (pag) => {
  pagination.value.current_page = pag.current;
  pagination.value.per_page = pag.pageSize;
  loadTiers(pag.current);
};

function onMobilePaginationChange(pageNum) {
  loadTiers(pageNum);
}

const tablePagination = computed(() => ({
  current: pagination.value.current_page,
  total: pagination.value.total,
  pageSize: pagination.value.per_page,
  showSizeChanger: true,
  showQuickJumper: true,
  showTotal: (total, range) =>
    `${range[0]}-${range[1]} of ${total} tiers`,
}));

// Watch for search/filter changes
watchDebounced(
  [tierSearch, statusFilter],
  () => {
    loadTiers(1); // Reset to first page when searching
  },
  { debounce: 300 }
);

// Methods
const editTier = (tier) => {
  editingTier.value = tier;
  showEditTierModal.value = true;
};

const deleteTier = (tier) => {
  console.log("Attempting to delete tier:", tier);

  Modal.confirm({
    title: "Delete Tier",
    content: `Are you sure you want to delete the ${tier.display_name} tier? This action cannot be undone.`,
    okText: "Yes, Delete",
    okType: "danger",
    cancelText: "Cancel",
    onOk: async () => {
      try {
        console.log(`Deleting tier ID: ${tier.id}`);
        const response = await axios.delete(`${getApiUrl("tiers")}/${tier.id}`);
        console.log("Delete response:", response.data);

        notification.success({
          message: "Tier Deleted",
          description: `${tier.display_name} tier has been deleted successfully`,
        });
        loadTiers(pagination.value.current_page);
      } catch (error) {
        console.error("Delete tier error:", error);
        console.error("Error response:", error.response);

        let errorMessage = "Failed to delete tier";
        if (error.response?.data?.error) {
          errorMessage = error.response.data.error;
        } else if (error.response?.data?.message) {
          errorMessage = error.response.data.message;
        } else if (error.message) {
          errorMessage = error.message;
        }

        notification.error({
          message: "Delete Failed",
          description: errorMessage,
        });
      }
    },
  });
};

const toggleTierStatus = async (tier, checked) => {
  const tierIndex = tiers.value.findIndex((t) => t.id === tier.id);

  try {
    // Update local state immediately for better UX
    if (tierIndex !== -1) {
      tiers.value[tierIndex].is_active = checked;
    }

    await axios.put(`${getApiUrl("tiers")}/${tier.id}`, {
      display_name: tier.display_name,
      multiplier: tier.multiplier,
      spending_threshold: tier.spending_threshold,
      color: tier.color,
      description: tier.description || "",
      sort_order: tier.sort_order,
      is_active: checked,
    });

    notification.success({
      message: "Tier Updated",
      description: `${tier.display_name} tier ${
        checked ? "activated" : "deactivated"
      }`,
    });
  } catch (error) {
    // Revert local state if API call fails
    if (tierIndex !== -1) {
      tiers.value[tierIndex].is_active = !checked;
    }

    console.error("Toggle tier status error:", error);
    notification.error({
      message: "Update Failed",
      description:
        error.response?.data?.message || "Failed to update tier status",
    });
  }
};

const closeModal = () => {
  showAddTierModal.value = false;
  showEditTierModal.value = false;
  editingTier.value = null;
};

const saveTier = async (tierData) => {
  console.log("Saving tier data:", tierData);
  console.log("Editing tier:", editingTier.value);

  savingTier.value = true;
  try {
    let response;
    if (editingTier.value) {
      // Update existing tier
      console.log(`Updating tier ID: ${editingTier.value.id}`);
      response = await axios.put(
        `${getApiUrl("tiers")}/${editingTier.value.id}`,
        tierData
      );
      notification.success({
        message: "Tier Updated",
        description: `${tierData.display_name} tier has been updated successfully`,
      });
    } else {
      // Create new tier
      console.log("Creating new tier");
      response = await axios.post(getApiUrl("tiers"), tierData);
      notification.success({
        message: "Tier Created",
        description: `${tierData.display_name} tier has been created successfully`,
      });
    }

    console.log("Save response:", response.data);
    closeModal();
    loadTiers(pagination.value.current_page);
  } catch (error) {
    console.error("Save tier error:", error);
    console.error("Error response:", error.response);

    let errorMessage = "Failed to save tier";
    if (error.response?.data?.errors) {
      // Handle validation errors
      const errors = error.response.data.errors;
      console.log("Validation errors:", errors);
      const firstError = Object.values(errors)[0];
      errorMessage = Array.isArray(firstError) ? firstError[0] : firstError;
    } else if (error.response?.data?.message) {
      errorMessage = error.response.data.message;
    } else if (error.message) {
      errorMessage = error.message;
    }

    notification.error({
      message: "Save Failed",
      description: errorMessage,
    });
  } finally {
    savingTier.value = false;
  }
};

// Initialize on mount
onMounted(() => {
  loadTiers();
});
</script>


<template>
  <div class="p-4 md:p-6">
    <!-- Search and Filters -->
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div class="overflow-x-auto">
        <a-radio-group v-model:value="statusFilter" button-style="solid">
          <a-radio-button value="">All</a-radio-button>
          <a-radio-button value="active">Active</a-radio-button>
          <a-radio-button value="inactive">Inactive</a-radio-button>
        </a-radio-group>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <a-input
          v-model:value="tierSearch"
          placeholder="Search tiers by name..."
          class="w-full sm:w-[300px]"
          allow-clear
        />

        <refresh-button
          :loading="loading"
          @click="() => loadTiers(pagination.current_page)"
        />

        <a-button
          @click="showAddTierModal = true"
          type="primary"
          class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 sm:w-auto"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Add New Tier
        </a-button>
      </div>
    </div>

    <!-- Tiers Table -->
    <a-table
      v-if="isMdUp"
      :columns="tierColumns"
      :data-source="tiers"
      :loading="loading"
      row-key="id"
      :pagination="tablePagination"
      @change="handleTableChange"
      bordered
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'color'">
          <div class="flex items-center">
            <div
              class="w-6 h-6 rounded-full mr-2 border"
              :style="{ backgroundColor: record.color }"
            ></div>
            <span class="font-mono text-sm">{{ record.color }}</span>
          </div>
        </template>

        <template v-if="column.key === 'multiplier'">
          <span class="font-medium">{{ record.multiplier }}x</span>
        </template>

        <template v-if="column.key === 'spending_threshold'">
          <span class="font-medium"
            >₱{{ record.spending_threshold?.toLocaleString() || 0 }}</span
          >
        </template>

        <template v-if="column.key === 'is_active'">
          <a-switch
            :checked="record.is_active"
            @change="(checked) => toggleTierStatus(record, checked)"
            size="small"
          />
        </template>

        <template v-if="column.key === 'actions'">
          <a-space>
            <IconTooltip
              name="Edit Tier"
              hover="hover:bg-blue-500"
              @click="editTier(record)"
            >
              <IconEdit size="20" class="mx-auto" />
            </IconTooltip>
            <IconTooltip
              name="Delete Tier"
              hover="hover:bg-red-500"
              @click="deleteTier(record)"
            >
              <IconTrash size="20" class="mx-auto" />
            </IconTooltip>
          </a-space>
        </template>
      </template>
    </a-table>

    <div v-else>
      <a-spin :spinning="loading">
        <div
          v-if="!tiers.length"
          class="py-12 text-center text-sm text-gray-500"
        >
          No tiers found
        </div>
        <div v-else class="flex flex-col gap-3">
          <div
            v-for="record in tiers"
            :key="record.id"
            class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
          >
            <div class="flex items-center gap-3 px-4 py-3">
              <div
                class="h-8 w-8 shrink-0 rounded-full border"
                :style="{ backgroundColor: record.color }"
              ></div>
              <div class="min-w-0 flex-1">
                <div class="font-semibold text-gray-900">
                  {{ record.display_name }}
                </div>
                <div class="text-sm text-gray-500">
                  {{ record.multiplier }}x multiplier
                </div>
              </div>
              <a-switch
                :checked="record.is_active"
                size="small"
                @change="(checked) => toggleTierStatus(record, checked)"
              />
            </div>

            <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
              <div
                class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm"
              >
                <span class="text-gray-500">Threshold</span>
                <span class="text-right font-semibold text-gray-900">
                  ₱{{ record.spending_threshold?.toLocaleString() || 0 }}
                </span>
                <span class="text-gray-500">Color</span>
                <span class="text-right font-mono text-gray-900">
                  {{ record.color }}
                </span>
                <span class="text-gray-500">Status</span>
                <span class="text-right font-medium text-gray-900">
                  {{ record.is_active ? "Active" : "Inactive" }}
                </span>
              </div>
            </div>

            <div class="border-t border-gray-100 px-4 py-3">
              <div class="flex flex-col gap-2">
                <a-button
                  class="flex items-center justify-center gap-2"
                  @click="editTier(record)"
                >
                  <template #icon>
                    <IconEdit size="18" />
                  </template>
                  Edit tier
                </a-button>
                <a-button
                  danger
                  class="flex items-center justify-center gap-2"
                  @click="deleteTier(record)"
                >
                  <template #icon>
                    <IconTrash size="18" />
                  </template>
                  Delete tier
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

    <!-- Tier Form Modal -->
    <TierFormModal
      :visible="showAddTierModal || showEditTierModal"
      :editing-tier="editingTier"
      :saving="savingTier"
      @close="closeModal"
      @save="saveTier"
    />
  </div>
</template>

