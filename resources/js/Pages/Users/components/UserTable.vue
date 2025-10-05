<template>
  <a-table
    class="ant-table-striped"
    :columns="columns"
    :data-source="users"
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

      <template v-if="column.key === 'roles'">
        <div class="flex flex-wrap gap-1">
          <a-tag
            v-for="role in record.roles"
            :key="role.id"
            :color="getRoleColor(role.name)"
            class="font-medium"
          >
            {{ role.name }}
          </a-tag>
        </div>
      </template>

      <template v-if="column.key === 'status'">
        <a-badge
          :status="record.email_verified_at ? 'success' : 'warning'"
          :text="record.email_verified_at ? 'Active' : 'Pending'"
        />
      </template>

      <template v-if="column.key === 'created_at'">
        <div class="text-sm">
          {{ formatDate(record.created_at) }}
        </div>
      </template>

      <template v-if="column.key === 'hierarchy'">
        <div class="text-sm">
          <div v-if="record.is_super_user" class="flex items-center gap-1">
            <a-tag color="purple" class="font-medium">
              <template #icon>
                <IconCrown size="12" />
              </template>
              Super User
            </a-tag>
          </div>
          <div v-else class="space-y-1">
            <div v-if="getUserHierarchyLevel(record) !== null" class="flex items-center gap-1">
              <a-tag :color="getHierarchyLevelColor(getUserHierarchyLevel(record))" class="font-medium">
                Level {{ getUserHierarchyLevel(record) }}
              </a-tag>
            </div>
            <div v-if="record.supervisor" class="text-xs text-gray-500">
              Reports to: {{ record.supervisor.name }}
            </div>
            <div v-if="getSubordinatesCount(record) > 0" class="text-xs text-gray-500">
              Manages: {{ getSubordinatesCount(record) }} user(s)
            </div>
          </div>
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
            v-if="canEdit(record)"
            hover="group-hover:bg-green-500"
            name="Edit User"
            @click="$emit('edit', record)"
          >
            <IconEdit size="20" class="mx-auto" />
          </IconTooltipButton>

          <IconTooltipButton
            v-if="canDelete(record)"
            hover="group-hover:bg-red-500"
            name="Delete User"
            @click="handleDelete(record)"
          >
            <IconTrash size="20" class="mx-auto" />
          </IconTooltipButton>
        </div>
      </template>
    </template>
  </a-table>
</template>

<script setup>
import { computed } from "vue";
import { IconEye, IconEdit, IconTrash, IconCrown } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { Modal, notification } from "ant-design-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { usePermissions } from "@/Composables/usePermissions";

const page = usePage();

// Use permission composable
const { canManageUsers, isSuperUser } = usePermissions();

// Props
const props = defineProps({
  users: {
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
  hierarchy: {
    type: Object,
    default: () => ({}),
  },
});

// Emits
const emit = defineEmits(['change', 'edit', 'view']);

// Table columns
const columns = [
  {
    title: "User",
    dataIndex: "name",
    key: "name",
    width: "30%",
  },
  {
    title: "Role(s)",
    key: "roles",
    width: "20%",
  },
  {
    title: "Status",
    key: "status",
    align: "center",
    width: "15%",
  },
  {
    title: "Created",
    dataIndex: "created_at",
    key: "created_at",
    align: "center",
    width: "15%",
  },
  {
    title: "Hierarchy",
    key: "hierarchy",
    align: "center",
    width: "20%",
  },
  {
    title: "Actions",
    key: "actions",
    align: "center",
    width: "1%",
  },
];

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

// Methods
const handleChange = (pagination, filters, sorter) => {
  emit('change', pagination, filters, sorter);
};

const canEdit = (user) => {
  // Super user can edit anyone
  if (isSuperUser.value) {
    return true;
  }
  
  // Users with manage permissions can edit
  if (!canManageUsers.value) {
    return false;
  }
  
  // admin can edit users except super users
  if (currentUser.value.roles?.some(role => role.name.toLowerCase() === 'admin')) {
    return !user.is_super_user;
  }
  
  return false;
};

const canDelete = (user) => {
  // Super user can delete anyone (except themselves)
  if (isSuperUser.value) {
    return user.id !== currentUser.value.id;
  }
  
  // Only users with manage permissions can delete
  if (!canManageUsers.value) {
    return false;
  }
  
  // Cannot delete yourself
  if (user.id === currentUser.value.id) {
    return false;
  }
  
  // Cannot delete super users
  if (user.is_super_user) {
    return false;
  }
  
  return true;
};

const handleDelete = (user) => {
  Modal.confirm({
    title: "Delete User",
    content: `Are you sure you want to delete ${user.name}? This action cannot be undone.`,
    okText: "Yes, Delete",
    okType: "danger",
    cancelText: "Cancel",
    onOk: async () => {
      try {
        await axios.delete(`/api/users/${user.id}`);
        notification.success({
          message: "User Deleted",
          description: `${user.name} has been deleted successfully`,
        });
        // Refresh the page data
        window.location.reload();
      } catch (error) {
        console.error("Delete user error:", error);
        notification.error({
          message: "Delete Failed",
          description: error.response?.data?.message || "Failed to delete user",
        });
      }
    },
  });
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

const getRoleColor = (roleName) => {
  const roleColors = {
    'Super Admin': 'red',
    'Admin': 'orange',
    'Manager': 'blue',
    'Cashier': 'green',
  };
  return roleColors[roleName] || 'default';
};

const formatDate = (date) => {
  if (!date) return "N/A";
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};

// Hierarchy helper methods
const getUserHierarchyLevel = (user) => {
  if (user.is_super_user) return -1;
  
  if (!user.roles || user.roles.length === 0) return null;
  
  const userRole = user.roles[0]?.name?.toLowerCase();
  if (!userRole || !props.hierarchy[userRole]) return null;
  
  return props.hierarchy[userRole].level;
};

const getHierarchyLevelColor = (level) => {
  if (level === -1) return 'purple'; // Super user
  if (level === 1) return 'red';     // Grand Manager
  if (level === 2) return 'orange';  // Admin
  if (level === 3) return 'blue';    // Manager
  if (level === 4) return 'cyan';    // Supervisor
  if (level === 5) return 'green';   // Cashier
  return 'default';
};

const getSubordinatesCount = (user) => {
  // This would need to be calculated on the backend
  // For now, return 0 as we don't have this data
  return 0;
};
</script>
