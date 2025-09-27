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
            v-if="canToggleStatus(record)"
            hover="group-hover:bg-orange-500"
            :name="record.status === 'active' ? 'Deactivate User' : 'Activate User'"
            @click="handleToggleStatus(record)"
          >
            <IconUserX v-if="record.status === 'active'" size="20" class="mx-auto" />
            <IconUserCheck v-else size="20" class="mx-auto" />
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
import { IconEye, IconEdit, IconTrash, IconUserX, IconUserCheck } from "@tabler/icons-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { Modal, notification } from "ant-design-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";

const page = usePage();

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
    title: "Actions",
    key: "actions",
    align: "center",
    width: "20%",
  },
];

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

// Methods
const handleChange = (pagination, filters, sorter) => {
  emit('change', pagination, filters, sorter);
};

const canEdit = (user) => {
  const currentUserRoles = currentUser.value?.roles?.map(role => role.name.toLowerCase()) || [];
  const targetUserRoles = user.roles?.map(role => role.name.toLowerCase()) || [];
  
  // super admin can edit anyone except other super admins
  if (currentUserRoles.includes('super admin')) {
    return !targetUserRoles.includes('super admin') || user.id === currentUser.value?.id;
  }
  
  // admin can edit users except super admins and other admins
  if (currentUserRoles.includes('admin')) {
    return !targetUserRoles.includes('super admin') && !targetUserRoles.includes('admin');
  }
  
  // manager can edit users except super admins, admins, and other managers
  if (currentUserRoles.includes('manager')) {
    return !targetUserRoles.includes('super admin') && 
           !targetUserRoles.includes('admin') && 
           !targetUserRoles.includes('manager');
  }
  
  return false;
};

const canDelete = (user) => {
  const currentUserRoles = currentUser.value?.roles?.map(role => role.name.toLowerCase()) || [];
  const targetUserRoles = user.roles?.map(role => role.name.toLowerCase()) || [];
  
  // Only super admin can delete users
  if (!currentUserRoles.includes('super admin')) {
    return false;
  }
  
  // Super admin cannot delete other super admins (should use inactive instead)
  if (targetUserRoles.includes('super admin')) {
    return false;
  }
  
  // Cannot delete yourself
  if (user.id === currentUser.value?.id) {
    return false;
  }
  
  return true;
};

const canToggleStatus = (user) => {
  const currentUserRoles = currentUser.value?.roles?.map(role => role.name.toLowerCase()) || [];
  const targetUserRoles = user.roles?.map(role => role.name.toLowerCase()) || [];
  
  // Only super admin can toggle status of super admins
  if (targetUserRoles.includes('super admin')) {
    return currentUserRoles.includes('super admin') && user.id !== currentUser.value?.id;
  }
  
  return false;
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

const handleToggleStatus = (user) => {
  const isActive = user.status === 'active'; // Assuming we have a status field
  const action = isActive ? 'deactivate' : 'activate';
  
  Modal.confirm({
    title: `${action.charAt(0).toUpperCase() + action.slice(1)} User`,
    content: `Are you sure you want to ${action} ${user.name}?`,
    okText: `Yes, ${action.charAt(0).toUpperCase() + action.slice(1)}`,
    okType: isActive ? "danger" : "primary",
    cancelText: "Cancel",
    onOk: async () => {
      try {
        await axios.patch(`/api/users/${user.id}/toggle-status`);
        notification.success({
          message: "Status Updated",
          description: `${user.name} has been ${action}d successfully`,
        });
        // Refresh the page data
        window.location.reload();
      } catch (error) {
        console.error("Toggle status error:", error);
        notification.error({
          message: "Update Failed",
          description: error.response?.data?.message || `Failed to ${action} user`,
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
</script>
