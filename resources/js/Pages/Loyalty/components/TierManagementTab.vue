<script setup>
import { ref, onMounted } from "vue";
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

// Load tiers with search and filter
const loadTiers = async (page = 1) => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    if (tierSearch.value) params.append("search", tierSearch.value);
    if (statusFilter.value) params.append("status", statusFilter.value);
    params.append("page", page);
    params.append("per_page", pagination.value.per_page);

    const response = await axios.get(`/api/loyalty/tiers?${params}`);
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
        const response = await axios.delete(`/api/loyalty/tiers/${tier.id}`);
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

    await axios.put(`/api/loyalty/tiers/${tier.id}`, {
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
        `/api/loyalty/tiers/${editingTier.value.id}`,
        tierData
      );
      notification.success({
        message: "Tier Updated",
        description: `${tierData.display_name} tier has been updated successfully`,
      });
    } else {
      // Create new tier
      console.log("Creating new tier");
      response = await axios.post("/api/loyalty/tiers", tierData);
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
  <div class="p-6">
    <!-- Search and Filters -->
    <div class="flex justify-between mb-4 gap-2">
      <div class="flex items-center gap-2">
        <a-radio-group v-model:value="statusFilter" button-style="solid">
          <a-radio-button value="">All</a-radio-button>
          <a-radio-button value="active">Active</a-radio-button>
          <a-radio-button value="inactive">Inactive</a-radio-button>
        </a-radio-group>
      </div>
      <div class="flex items-center gap-2">
        <div>
          <a-input
            v-model:value="tierSearch"
            placeholder="Search tiers by name..."
            class="w-[300px]"
            allow-clear
          />
        </div>

        <refresh-button
          :loading="loading"
          @click="() => loadTiers(pagination.current_page)"
        />

        <a-button
          @click="showAddTierModal = true"
          type="primary"
          class="bg-white border flex items-center border-green-500 text-green-500"
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
      :columns="tierColumns"
      :data-source="tiers"
      :loading="loading"
      row-key="id"
      :pagination="{
        current: pagination.current_page,
        total: pagination.total,
        pageSize: pagination.per_page,
        showSizeChanger: true,
        showQuickJumper: true,
        showTotal: (total, range) =>
          `${range[0]}-${range[1]} of ${total} tiers`,
      }"
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

