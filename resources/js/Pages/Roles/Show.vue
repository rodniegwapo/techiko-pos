<script setup>
import { computed } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { ArrowLeftOutlined, EditOutlined } from "@ant-design/icons-vue";
import { IconShield, IconUsers, IconAlertTriangle } from "@tabler/icons-vue";
import { usePage } from "@inertiajs/vue3";

const page = usePage();

const props = defineProps({
  role: Object,
});

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

// Check if current user can edit roles
const canEdit = computed(() => {
  return currentUser.value?.roles?.some(role => role.name.toLowerCase() === 'super admin');
});

// Check if this is a system role
const isSystemRole = computed(() => {
  if (!props.role || !props.role.name) return false;
  const systemRoles = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];
  return systemRoles.includes(props.role.name.toLowerCase());
});

// Group permissions by module
const groupedPermissions = computed(() => {
  if (!props.role?.permissions) return {};
  
  return props.role.permissions.reduce((groups, permission) => {
    const module = permission.module || 'other';
    if (!groups[module]) {
      groups[module] = [];
    }
    groups[module].push(permission);
    return groups;
  }, {});
});

// Methods
const handleBack = () => {
  router.visit(route('roles.index'));
};

const handleEdit = () => {
  router.visit(route('roles.edit', props.role.id));
};

const getRoleColor = (roleName) => {
  const roleColors = {
    'Super Admin': '#f56565',
    'Admin': '#ed8936',
    'Manager': '#4299e1',
    'Supervisor': '#9f7aea',
    'Cashier': '#48bb78',
  };
  return roleColors[roleName] || '#a0aec0';
};

const getPermissionLabel = (permissionName) => {
  const parts = permissionName.split('.');
  if (parts.length < 2) return permissionName;
  
  const action = parts[1];
  const actionLabels = {
    'view': 'View',
    'create': 'Create',
    'edit': 'Edit',
    'delete': 'Delete',
    'apply': 'Apply',
    'manage': 'Manage',
    'adjust_points': 'Adjust Points',
    'export': 'Export',
    'dashboard': 'Dashboard',
    'products': 'Products',
    'movements': 'Movements',
    'adjustments': 'Adjustments',
    'locations': 'Locations',
    'valuation': 'Valuation',
    'receive': 'Receive',
    'transfer': 'Transfer',
    'low_stock': 'Low Stock',
    'tiers_manage': 'Manage Tiers',
    'customers_manage': 'Manage Customers',
    'points_adjust': 'Adjust Points',
    'reports_view': 'View Reports',
  };
  
  return actionLabels[action] || action.charAt(0).toUpperCase() + action.slice(1);
};

const formatDate = (dateString) => {
  if (!dateString) return '-';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
};
</script>

<template>
  <Head title="Role Details" />

  <AuthenticatedLayout>
    <ContentHeader title="Role Details" />

    <div v-if="role" class="max-w-4xl mx-auto p-6 space-y-6">
        <!-- Role Header -->
        <div class="flex items-center space-x-4 p-6 bg-white border rounded-lg">
          <a-avatar 
            :size="80"
            :style="{ backgroundColor: getRoleColor(role.name) }"
          >
            <IconShield size="40" />
          </a-avatar>
          <div class="flex-1">
            <h2 class="text-2xl font-semibold text-gray-900">{{ role.name }}</h2>
            <p class="text-sm text-gray-600 mt-1">
              {{ role.description || 'No description provided' }}
            </p>
            <div class="flex items-center space-x-4 mt-3">
              <a-tag :color="getRoleColor(role.name)" class="font-medium" size="large">
                {{ role.permissions?.length || 0 }} Permissions
              </a-tag>
              <a-tag color="blue" v-if="role.users_count" size="large">
                {{ role.users_count }} Users
              </a-tag>
            </div>
          </div>
          <div class="text-right">
            <div class="text-sm text-gray-500">Created</div>
            <div class="text-sm font-medium">{{ formatDate(role.created_at) }}</div>
            <div class="text-sm text-gray-500 mt-1">Last Updated</div>
            <div class="text-sm font-medium">{{ formatDate(role.updated_at) }}</div>
          </div>
        </div>

        <!-- System Role Warning -->
        <div v-if="isSystemRole" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
          <div class="flex items-center">
            <IconAlertTriangle class="text-yellow-600 mr-2" />
            <div>
              <h5 class="font-medium text-yellow-800">System Role</h5>
              <p class="text-sm text-yellow-700 mt-1">
                This is a system role and cannot be deleted. Some permissions may be restricted.
              </p>
            </div>
          </div>
        </div>

        <!-- Permissions Section -->
        <div class="bg-white border rounded-lg p-6">
          <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <IconShield class="mr-2 text-blue-600" />
            Permissions
          </h4>
          
          <div v-if="role.permissions && role.permissions.length > 0" class="space-y-4">
            <div
              v-for="(modulePermissions, moduleName) in groupedPermissions"
              :key="moduleName"
              class="border rounded-lg p-4"
            >
              <h5 class="font-medium text-gray-800 mb-3 capitalize text-lg">
                {{ moduleName.replace('_', ' ') }}
              </h5>
              <div class="flex flex-wrap gap-2">
                <a-tag
                  v-for="permission in modulePermissions"
                  :key="permission.id"
                  color="blue"
                  class="text-sm"
                >
                  {{ getPermissionLabel(permission.name) }}
                </a-tag>
              </div>
            </div>
          </div>
          
          <div v-else class="text-center py-8 text-gray-500">
            <IconShield size="48" class="mx-auto mb-2 opacity-50" />
            <p>No permissions assigned to this role</p>
          </div>
        </div>

        <!-- Users Section -->
        <div v-if="role.users_count > 0" class="bg-white border rounded-lg p-6">
          <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <IconUsers class="mr-2 text-green-600" />
            Assigned Users
          </h4>
          <div class="text-center py-4">
            <a-badge
              :count="role.users_count"
              :number-style="{ backgroundColor: '#52c41a', fontSize: '20px' }"
            />
            <p class="text-sm text-gray-600 mt-2">
              {{ role.users_count }} user{{ role.users_count !== 1 ? 's' : '' }} assigned to this role
            </p>
          </div>
        </div>

        <!-- Role Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-white border rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{{ role.permissions?.length || 0 }}</div>
            <div class="text-sm text-gray-600">Total Permissions</div>
          </div>
          <div class="bg-white border rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-green-600">{{ Object.keys(groupedPermissions).length }}</div>
            <div class="text-sm text-gray-600">Permission Modules</div>
          </div>
          <div class="bg-white border rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">{{ role.users_count || 0 }}</div>
            <div class="text-sm text-gray-600">Assigned Users</div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-end space-x-4 pt-6 border-t">
          <a-button @click="handleBack">
            <template #icon>
              <ArrowLeftOutlined />
            </template>
            Back to Roles
          </a-button>
          <a-button
            v-if="canEdit"
            type="primary"
            @click="handleEdit"
          >
            <template #icon>
              <EditOutlined />
            </template>
            Edit Role
          </a-button>
        </div>
    </div>
  </AuthenticatedLayout>
</template>
