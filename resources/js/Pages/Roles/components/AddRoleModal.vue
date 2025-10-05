<script setup>
import { ref, watch, reactive, computed } from "vue";
import { notification } from "ant-design-vue";
import { IconShield } from "@tabler/icons-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";

const page = usePage();

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  role: {
    type: Object,
    default: null,
  },
  isEdit: {
    type: Boolean,
    default: false,
  },
  permissions: {
    type: Object,
    default: () => ({}),
  },
});

// Emits
const emit = defineEmits(["close", "saved"]);

// Form reference
const formRef = ref();
const saving = ref(false);

// Form data
const form = reactive({
  name: "",
  description: "",
  permissions: [],
});

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

// Selected permissions count
const selectedPermissionsCount = computed(() => {
  return form.permissions.length;
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
  permissions: [
    { required: true, message: "Please select at least one permission" },
  ],
}));

// Watch for role changes
watch(
  () => props.role,
  (newRole) => {
    if (newRole && props.isEdit) {
      Object.assign(form, {
        name: newRole.name || "",
        description: newRole.description || "",
        permissions: newRole.permissions?.map(p => p.id) || [],
      });
    } else {
      // Reset form for new role
      Object.assign(form, {
        name: "",
        description: "",
        permissions: [],
      });
    }
  },
  { immediate: true }
);

// Methods
const handleSave = async () => {
  try {
    await formRef.value.validate();
    saving.value = true;

    const roleData = {
      name: form.name,
      description: form.description,
      permissions: form.permissions,
    };

    console.log("Saving role data:", roleData);

    if (props.isEdit && props.role) {
      // Update existing role
      await axios.put(`/roles/${props.role.id}`, roleData);
      notification.success({
        message: "Role Updated",
        description: `Role "${roleData.name}" has been updated successfully`,
      });
    } else {
      // Create new role
      await axios.post("/roles", roleData);
      notification.success({
        message: "Role Created",
        description: `Role "${roleData.name}" has been created successfully`,
      });
    }

    emit("saved");
  } catch (error) {
    console.error("Save role error:", error);

    let errorMessage = "Failed to save role";
    if (error.response?.data?.errors) {
      const errors = error.response.data.errors;
      const firstError = Object.values(errors)[0];
      errorMessage = Array.isArray(firstError) ? firstError[0] : firstError;
    } else if (error.response?.data?.message) {
      errorMessage = error.response.data.message;
    }

    notification.error({
      message: "Save Failed",
      description: errorMessage,
    });
  } finally {
    saving.value = false;
  }
};

const handleCancel = () => {
  emit("close");
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
  <a-modal
    :visible="visible"
    :title="isEdit ? 'Edit Role' : 'Add New Role'"
    :confirm-loading="saving"
    @ok="handleSave"
    @cancel="handleCancel"
    width="800px"
  >
    <a-form
      ref="formRef"
      :model="form"
      :rules="rules"
      layout="vertical"
      @finish="handleSave"
    >
      <div class="grid grid-cols-1 gap-4">
        <a-form-item
          label="Role Name"
          name="name"
        >
          <a-input
            v-model:value="form.name"
            placeholder="Enter role name (e.g., 'Sales Manager')"
          />
        </a-form-item>

        <a-form-item
          label="Description"
          name="description"
        >
          <a-textarea
            v-model:value="form.description"
            placeholder="Enter role description"
            :rows="3"
          />
        </a-form-item>

        <!-- Permission Selection -->
        <a-form-item
          label="Permissions"
          name="permissions"
        >
          <div class="border rounded-lg p-4 max-h-96 overflow-y-auto">
            <div
              v-for="(modulePermissions, moduleName) in permissions"
              :key="moduleName"
              class="mb-6 last:mb-0"
            >
              <h4 class="text-sm font-semibold text-gray-900 mb-3 capitalize border-b pb-1">
                {{ moduleName.replace('_', ' ') }}
              </h4>
              <a-checkbox-group
                v-model:value="form.permissions"
                class="grid grid-cols-2 gap-2"
              >
                <a-checkbox
                  v-for="permission in modulePermissions"
                  :key="permission.id"
                  :value="permission.id"
                  class="text-sm"
                >
                  {{ getPermissionLabel(permission.name) }}
                </a-checkbox>
              </a-checkbox-group>
            </div>
          </div>
        </a-form-item>
      </div>
    </a-form>

    <!-- Role Preview -->
    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
      <h4 class="text-sm font-medium text-gray-900 mb-3">Preview</h4>
      <div class="flex items-center space-x-4">
        <a-avatar 
          :size="48"
          :style="{ backgroundColor: getRoleColor(form.name) }"
        >
          <IconShield size="24" />
        </a-avatar>
        <div>
          <div class="font-medium">{{ form.name || 'Role Name' }}</div>
          <div class="text-sm text-gray-500">{{ form.description || 'Role description' }}</div>
          <div class="text-xs font-medium mt-1">
            <a-tag :color="getRoleColor(form.name)">
              {{ selectedPermissionsCount }} permissions
            </a-tag>
          </div>
        </div>
      </div>
    </div>
  </a-modal>
</template>
