<script setup>
import { ref, reactive, computed } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { ArrowLeftOutlined, SaveOutlined } from "@ant-design/icons-vue";
import { IconShield } from "@tabler/icons-vue";
import { notification } from "ant-design-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const { spinning } = useGlobalVariables();

const props = defineProps({
  permissions: Object,
});

// Form state
const form = reactive({
  name: "",
  description: "",
  permissions: [],
});

const loading = ref(false);

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
  permissions: [
    { required: true, message: "Please select at least one permission" },
  ],
}));

// Selected permissions count
const selectedPermissionsCount = computed(() => {
  return form.permissions.length;
});

// Methods
const handleSave = async () => {
  try {
    loading.value = true;

    const roleData = {
      name: form.name,
      description: form.description,
      permissions: form.permissions,
    };

    await router.post(route('roles.store'), roleData, {
      onStart: () => {
        spinning.value = true;
      },
      onSuccess: () => {
        notification.success({
          message: "Role Created",
          description: `Role "${roleData.name}" has been created successfully`,
        });
      },
      onError: (errors) => {
        console.error("Save role error:", errors);
        notification.error({
          message: "Save Failed",
          description: "Failed to create role. Please check the form for errors.",
        });
      },
      onFinish: () => {
        spinning.value = false;
        loading.value = false;
      },
    });

  } catch (error) {
    console.error("Save role error:", error);
    notification.error({
      message: "Save Failed",
      description: "Failed to create role. Please try again.",
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
</script>

<template>
  <Head title="Create Role" />

  <AuthenticatedLayout>
    <ContentHeader title="Create New Role" />

    <div class="max-w-4xl mx-auto p-6 space-y-6">
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
              <h4 class="text-sm font-semibold text-gray-900 mb-3 capitalize border-b pb-1">
                {{ moduleName.replace('_', ' ') }}
              </h4>
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
          Create Role
        </a-button>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
