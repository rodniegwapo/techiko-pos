<script setup>
import { computed } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { ArrowLeftOutlined } from "@ant-design/icons-vue";

const props = defineProps({
  roles: Array,
  permissions: Object,
});

// Computed properties
const totalPermissions = computed(() => {
  return Object.values(props.permissions).reduce((total, modulePermissions) => {
    return total + modulePermissions.length;
  }, 0);
});

// Methods
const handleBack = () => {
  router.visit(route('roles.index'));
};

const hasPermission = (role, permissionId) => {
  return role.permissions?.some(p => p.id === permissionId) || false;
};

const getModulePermissionCount = (role, moduleName) => {
  if (!role.permissions) return 0;
  return role.permissions.filter(p => p.module === moduleName).length;
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
</script>

<template>
  <Head title="Permission Matrix" />

  <AuthenticatedLayout>
    <ContentHeader title="Permission Matrix" />

    <div class="max-w-7xl mx-auto p-6 space-y-6">
        <!-- Header Info -->
        <div class="bg-white border rounded-lg p-6">
          <h3 class="text-lg font-semibold text-gray-900 mb-2">Role Permission Matrix</h3>
          <p class="text-sm text-gray-600">View and manage permissions for all roles in the system</p>
        </div>

        <!-- Matrix Table -->
        <div class="bg-white border rounded-lg overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sticky left-0 bg-gray-50 z-10">
                    Permission
                  </th>
                  <th
                    v-for="role in roles"
                    :key="role.id"
                    class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[120px]"
                  >
                    <div class="flex flex-col items-center">
                      <div class="font-semibold">{{ role.name }}</div>
                      <div class="text-xs text-gray-400">{{ role.permissions?.length || 0 }} perms</div>
                    </div>
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <template v-for="(modulePermissions, moduleName) in permissions" :key="moduleName">
                  <!-- Module Header -->
                  <tr class="bg-blue-50">
                    <td class="px-4 py-2 font-semibold text-blue-900 sticky left-0 bg-blue-50 z-10">
                      {{ moduleName.replace('_', ' ').toUpperCase() }}
                    </td>
                    <td
                      v-for="role in roles"
                      :key="`${moduleName}-${role.id}`"
                      class="px-4 py-2 text-center"
                    >
                      <div class="text-xs text-blue-600 font-medium">
                        {{ getModulePermissionCount(role, moduleName) }}/{{ modulePermissions.length }}
                      </div>
                    </td>
                  </tr>
                  
                  <!-- Individual Permissions -->
                  <tr
                    v-for="permission in modulePermissions"
                    :key="permission.id"
                    class="hover:bg-gray-50"
                  >
                    <td class="px-4 py-2 text-sm text-gray-900 sticky left-0 bg-white z-10">
                      <div class="flex items-center">
                        <div class="w-2 h-2 bg-gray-300 rounded-full mr-2"></div>
                        {{ getPermissionLabel(permission.name) }}
                      </div>
                    </td>
                    <td
                      v-for="role in roles"
                      :key="`${permission.id}-${role.id}`"
                      class="px-4 py-2 text-center"
                    >
                      <div class="flex justify-center">
                        <a-tag
                          v-if="hasPermission(role, permission.id)"
                          color="green"
                          size="small"
                        >
                          ✓
                        </a-tag>
                        <a-tag
                          v-else
                          color="default"
                          size="small"
                        >
                          ✗
                        </a-tag>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-white border rounded-lg p-6 text-center">
            <div class="text-3xl font-bold text-blue-600 mb-2">{{ roles.length }}</div>
            <div class="text-sm text-gray-600">Total Roles</div>
          </div>
          <div class="bg-white border rounded-lg p-6 text-center">
            <div class="text-3xl font-bold text-green-600 mb-2">{{ totalPermissions }}</div>
            <div class="text-sm text-gray-600">Total Permissions</div>
          </div>
          <div class="bg-white border rounded-lg p-6 text-center">
            <div class="text-3xl font-bold text-purple-600 mb-2">{{ Object.keys(permissions).length }}</div>
            <div class="text-sm text-gray-600">Permission Modules</div>
          </div>
        </div>

        <!-- Legend -->
        <div class="bg-gray-50 border rounded-lg p-6">
          <h4 class="font-medium text-gray-900 mb-3">Legend</h4>
          <div class="flex items-center space-x-6 text-sm">
            <div class="flex items-center">
              <a-tag color="green" size="small" class="mr-2">✓</a-tag>
              <span>Permission granted</span>
            </div>
            <div class="flex items-center">
              <a-tag color="default" size="small" class="mr-2">✗</a-tag>
              <span>Permission not granted</span>
            </div>
          </div>
        </div>

        <!-- Role Summary -->
        <div class="bg-white border rounded-lg p-6">
          <h4 class="font-medium text-gray-900 mb-4">Role Summary</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div
              v-for="role in roles"
              :key="role.id"
              class="border rounded-lg p-4"
            >
              <div class="flex items-center justify-between mb-2">
                <h5 class="font-medium text-gray-900">{{ role.name }}</h5>
                <a-tag color="blue" size="small">
                  {{ role.permissions?.length || 0 }} perms
                </a-tag>
              </div>
              <div class="text-sm text-gray-600">
                {{ role.users_count || 0 }} users assigned
              </div>
            </div>
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
        </div>
    </div>
  </AuthenticatedLayout>
</template>
