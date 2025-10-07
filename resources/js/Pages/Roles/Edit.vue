<script setup>
import { ref, reactive, computed, onMounted } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { ArrowLeftOutlined, SaveOutlined } from "@ant-design/icons-vue";
import { IconShield, IconAlertTriangle } from "@tabler/icons-vue";
import { notification } from "ant-design-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissions } from "@/Composables/usePermissions";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const { spinning } = useGlobalVariables();

// Use permission composable
const { canManageRoles, isSuperUser } = usePermissions();

const props = defineProps({
  role: Object,
  permissions: Object,
});

// Extract role data from the wrapped structure
const roleData = computed(() => props.role?.data || props.role);

// Form state
const form = reactive({
  name: "",
  description: "",
  level: 1,
  permissions: [],
});

const loading = ref(false);

// Initialize form with existing role data
onMounted(() => {
  if (roleData.value) {
    form.name = roleData.value.name || "";
    form.description = roleData.value.description || "";
    form.level = roleData.value.level || 1;
    form.permissions = roleData.value.permissions?.map(p => p.id) || [];
  }
});

// Form validation rules
const rules = computed(() => ({
  name: [
    { required: true, message: "Please enter role name" },
    { min: 2, message: "Role name must be at least 2 characters" },
    { max: 255, message: "Role name cannot exceed 255 characters" },
  ],
  description: [
    { max: 500, message: "Description cannot exceed 500 characters" },
  ],
  level: [
    { required: true, message: "Please enter a role level" },
    {
      validator: (_rule, value) => {
        if (value == null || value === '') return Promise.reject("Please enter a role level");
        const num = Number(value);
        if (!Number.isInteger(num)) return Promise.reject("Level must be an integer");
        if (num < 1 || num > 99) return Promise.reject("Level must be between 1 and 99");
        return Promise.resolve();
      },
      trigger: 'change'
    }
  ],
  permissions: [
    { required: true, message: "Please select at least one permission" },
  ],
}));

// Selected permissions count
const selectedPermissionsCount = computed(() => {
  return form.permissions.length;
});

// Check if this is a system role
const isSystemRole = computed(() => {
  if (!roleData.value || !roleData.value.name) return false;
  const systemRoles = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];
  return systemRoles.includes(roleData.value.name.toLowerCase());
});

// Methods
const handleSave = async () => {
  try {
    loading.value = true;

    const formData = {
      name: form.name,
      description: form.description,
      level: form.level,
      permissions: form.permissions,
    };

    await router.put(route('roles.update', roleData.value.id), formData, {
      onStart: () => {
        spinning.value = true;
      },
      onSuccess: () => {
        notification.success({
          message: "Role Updated",
          description: `Role "${formData.name}" has been updated successfully`,
        });
      },
      onError: (errors) => {
        console.error("Update role error:", errors);
        notification.error({
          message: "Update Failed",
          description: "Failed to update role. Please check the form for errors.",
        });
      },
      onFinish: () => {
        spinning.value = false;
        loading.value = false;
      },
    });

  } catch (error) {
    console.error("Update role error:", error);
    notification.error({
      message: "Update Failed",
      description: "Failed to update role. Please try again.",
    });
    loading.value = false;
    spinning.value = false;
  }
};

const handleCancel = () => {
  router.visit(route('roles.index'));
};

const handlePermissionChange = (permissionId, checked) => {
  if (checked) {
    // Add permission if not already present
    if (!form.permissions.includes(permissionId)) {
      form.permissions.push(permissionId);
    }
  } else {
    // Remove permission
    const index = form.permissions.indexOf(permissionId);
    if (index > -1) {
      form.permissions.splice(index, 1);
    }
  }
};

const getRoleColor = (roleName) => {
  const roleColors = {
    'Super Admin': 'red',
    'Admin': 'orange',
    'Manager': 'blue',
    'Supervisor': 'purple',
    'Cashier': 'green',
  };
  return roleColors[roleName] || 'default';
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

// =======
// Select All helpers per module
// =======
const getModulePermissionIds = (moduleName) => {
  return (props.permissions?.[moduleName] || []).map(p => p.id);
};

const isModuleAllChecked = (moduleName) => {
  const ids = getModulePermissionIds(moduleName);
  if (ids.length === 0) return false;
  return ids.every(id => form.permissions.includes(id));
};

const isModuleIndeterminate = (moduleName) => {
  const ids = getModulePermissionIds(moduleName);
  if (ids.length === 0) return false;
  const checkedCount = ids.filter(id => form.permissions.includes(id)).length;
  return checkedCount > 0 && checkedCount < ids.length;
};

const onModuleCheckAllChange = (moduleName, checked) => {
  const ids = getModulePermissionIds(moduleName);
  if (checked) {
    const next = new Set(form.permissions);
    ids.forEach(id => next.add(id));
    form.permissions = Array.from(next);
  } else {
    form.permissions = form.permissions.filter(id => !ids.includes(id));
  }
};
</script>

<template>
  <Head title="Edit Role" />

  <AuthenticatedLayout>
    <ContentHeader title="Edit Role" />

    <div class="max-w-4xl mx-auto p-6 space-y-6">
      <!-- System Role Warning -->
      <a-alert
        v-if="isSystemRole"
        message="System Role"
        description="This is a system role. Some permissions may be restricted and the role cannot be deleted."
        type="warning"
        show-icon
        class="mb-4"
      >
        <template #icon>
          <IconAlertTriangle />
        </template>
      </a-alert>

      <a-form
        :model="form"
        :rules="rules"
        layout="vertical"
        @finish="handleSave"
      >
        <!-- Basic Information -->
        <a-card class="shadow-sm">
          <template #title>
            <span>Basic Information</span>
          </template>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <a-form-item
              label="Role Name"
              name="name"
              class="md:col-span-2"
            >
              <a-input
                v-model:value="form.name"
                placeholder="Enter role name (e.g., 'Sales Manager')"
                size="large"
              />
            </a-form-item>

          <a-form-item
            label="Level"
            name="level"
          >
            <a-input-number
              v-model:value="form.level"
              :min="1"
              :max="99"
              :step="1"
              style="width: 100%"
            />
          </a-form-item>

            <a-form-item
              label="Description"
              name="description"
              class="md:col-span-2"
            >
              <a-textarea
                v-model:value="form.description"
                placeholder="Enter role description"
                :rows="3"
              />
            </a-form-item>
          </div>
        </a-card>

        <!-- Permission Selection -->
        <a-card class="shadow-sm">
          <template #title>
            <span>Permissions</span>
          </template>
          
          <div class="border rounded-lg p-4 max-h-96 overflow-y-auto">
            <div
              v-for="(modulePermissions, moduleName) in permissions"
              :key="moduleName"
              class="mb-6 last:mb-0"
            >
              <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-semibold text-gray-900 capitalize">
                  {{ moduleName.replace('_', ' ') }}
                </h4>
                <a-checkbox
                  :checked="isModuleAllChecked(moduleName)"
                  :indeterminate="isModuleIndeterminate(moduleName)"
                  @change="onModuleCheckAllChange(moduleName, $event.target.checked)"
                >
                  Select all
                </a-checkbox>
              </div>

              <div class="grid grid-cols-2 gap-2">
                <a-checkbox
                  v-for="permission in modulePermissions"
                  :key="permission.id"
                  :checked="form.permissions.includes(permission.id)"
                  @change="handlePermissionChange(permission.id, $event.target.checked)"
                  class="text-sm"
                >
                  {{ getPermissionLabel(permission.name) }}
                </a-checkbox>
              </div>
            </div>
          </div>
        </a-card>

        <!-- Role Preview -->
        <a-card class="shadow-sm">
          <template #title>
            <span>Preview</span>
          </template>
          
          <div class="flex items-center space-x-4">
            <a-avatar 
              :size="64"
              :style="{ backgroundColor: getRoleColor(form.name) }"
            >
              <IconShield size="32" />
            </a-avatar>
            <div>
              <div class="text-xl font-medium">{{ form.name || 'Role Name' }}</div>
              <div class="text-sm text-gray-500">{{ form.description || 'Role description' }}</div>
              <div class="text-sm font-medium mt-2">
                <a-tag :color="getRoleColor(form.name)" size="large">
                  {{ selectedPermissionsCount }} permissions
                </a-tag>
              </div>
            </div>
          </div>
        </a-card>
      </a-form>

      <!-- Action Buttons -->
      <div class="flex justify-end space-x-4 pt-6 border-t">
        <a-button @click="handleCancel">
          <template #icon>
            <ArrowLeftOutlined />
          </template>
          Back to Roles
        </a-button>
        <a-button 
          type="primary" 
          @click="handleSave" 
          :loading="loading"
        >
          <template #icon>
            <SaveOutlined />
          </template>
          Update Role
        </a-button>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
