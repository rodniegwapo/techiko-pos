<template>
  <a-modal
    :visible="visible"
    :title="`User Details - ${user?.name || 'Unknown'}`"
    @cancel="$emit('close')"
    width="900px"
    :footer="null"
  >
    <div v-if="user" class="space-y-6">
      <!-- User Header -->
      <div class="flex items-center justify-between p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
        <div class="flex items-center space-x-4">
          <a-avatar 
            :size="64"
            :style="{ backgroundColor: getAvatarColor(user.name) }"
          >
            {{ getInitials(user.name) }}
          </a-avatar>
          <div>
            <h3 class="text-xl font-semibold text-gray-900">{{ user.name }}</h3>
            <p class="text-gray-600">{{ user.email }}</p>
            <div class="flex items-center mt-2 space-x-2">
              <a-tag
                v-for="role in user.roles"
                :key="role.id"
                :color="getRoleColor(role.name)"
                class="font-medium"
              >
                {{ role.name }}
              </a-tag>
            </div>
          </div>
        </div>
        <div class="text-right">
          <a-button 
            v-if="canEdit"
            type="primary" 
            @click="$emit('edit', user)"
          >
            <edit-outlined class="mr-1" />
            Edit User
          </a-button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Basic Information -->
        <div class="bg-white border rounded-lg p-4">
          <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <user-outlined class="mr-2 text-blue-600" />
            Basic Information
          </h4>
          <div class="space-y-3">
            <div class="flex justify-between">
              <span class="text-gray-600">Full Name:</span>
              <span class="font-medium">{{ user.name }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Email:</span>
              <span class="font-medium">{{ user.email }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Status:</span>
              <a-badge
                :status="user.email_verified_at ? 'success' : 'warning'"
                :text="user.email_verified_at ? 'Active' : 'Pending Verification'"
              />
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Created:</span>
              <span class="font-medium">{{ formatDate(user.created_at) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Last Updated:</span>
              <span class="font-medium">{{ formatDate(user.updated_at) }}</span>
            </div>
          </div>
        </div>

        <!-- Role & Permissions -->
        <div class="bg-white border rounded-lg p-4">
          <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <safety-certificate-outlined class="mr-2 text-purple-600" />
            Role & Permissions
          </h4>
          
          <div class="space-y-4">
            <!-- Roles -->
            <div>
              <h5 class="font-medium text-gray-700 mb-2">Assigned Roles:</h5>
              <div class="flex flex-wrap gap-2">
                <a-tag
                  v-for="role in user.roles"
                  :key="role.id"
                  :color="getRoleColor(role.name)"
                  class="font-medium px-3 py-1"
                >
                  {{ role.name }}
                </a-tag>
              </div>
            </div>

            <!-- Role Description -->
            <div v-if="user.roles && user.roles.length > 0">
              <h5 class="font-medium text-gray-700 mb-2">Permissions:</h5>
              <div class="text-sm text-gray-600 bg-gray-50 p-3 rounded">
                {{ getRoleDescription(user.roles[0].name) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Supervisor Assignment (for Cashiers) -->
      <div v-if="isCashier" class="bg-white border rounded-lg p-4">
        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
          <team-outlined class="mr-2 text-orange-600" />
          Supervisor Assignment
        </h4>
        
        <div class="space-y-4">
          <!-- Current Supervisor -->
          <div>
            <h5 class="font-medium text-gray-700 mb-2">Current Supervisor:</h5>
            <div v-if="user.supervisor" class="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-lg">
              <div class="flex items-center space-x-3">
                <a-avatar 
                  :size="32"
                  :style="{ backgroundColor: getAvatarColor(user.supervisor.name) }"
                >
                  {{ getInitials(user.supervisor.name) }}
                </a-avatar>
                <div>
                  <div class="font-medium text-green-800">{{ user.supervisor.name }}</div>
                  <div class="text-sm text-green-600">{{ user.supervisor.email }}</div>
                </div>
              </div>
              <a-button 
                v-if="canAssignSupervisor"
                type="text" 
                danger 
                size="small"
                @click="removeSupervisor"
                :loading="removingSupervisor"
              >
                Remove
              </a-button>
            </div>
            <div v-else class="p-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-500 text-center">
              No supervisor assigned
            </div>
          </div>

          <!-- Assign Supervisor -->
          <div v-if="canAssignSupervisor">
            <h5 class="font-medium text-gray-700 mb-2">Assign New Supervisor:</h5>
            <div class="flex space-x-2">
              <a-select
                v-model:value="selectedSupervisorId"
                placeholder="Select a supervisor"
                class="flex-1"
                :loading="loadingSupervisors"
                show-search
                :filter-option="filterSupervisorOption"
              >
                <a-select-option
                  v-for="supervisor in availableSupervisors"
                  :key="supervisor.id"
                  :value="supervisor.id"
                >
                  {{ supervisor.name }} ({{ supervisor.email }})
                </a-select-option>
              </a-select>
              <a-button 
                type="primary"
                @click="assignSupervisor"
                :disabled="!selectedSupervisorId"
                :loading="assigningSupervisor"
              >
                Assign
              </a-button>
            </div>
          </div>

          <!-- No Permission Message -->
          <div v-if="!canAssignSupervisor" class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
            <div class="text-sm text-yellow-800">
              <strong>Note:</strong> You don't have permission to assign supervisors. Contact an administrator for supervisor changes.
            </div>
          </div>
        </div>
      </div>

      <!-- Activity Summary -->
      <div class="bg-white border rounded-lg p-4">
        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
          <clock-circle-outlined class="mr-2 text-green-600" />
          Activity Summary
        </h4>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="text-center p-3 bg-blue-50 rounded-lg border border-blue-200">
            <div class="text-2xl font-bold text-blue-600">0</div>
            <div class="text-sm text-blue-800">Sales Processed</div>
          </div>
          <div class="text-center p-3 bg-green-50 rounded-lg border border-green-200">
            <div class="text-2xl font-bold text-green-600">0</div>
            <div class="text-sm text-green-800">Customers Served</div>
          </div>
          <div class="text-center p-3 bg-purple-50 rounded-lg border border-purple-200">
            <div class="text-2xl font-bold text-purple-600">
              {{ getAccountAge(user.created_at) }}
            </div>
            <div class="text-sm text-purple-800">Days Active</div>
          </div>
        </div>
        
        <div class="mt-4 text-center text-gray-500 text-sm">
          <clock-circle-outlined class="mr-1" />
          Activity tracking feature coming soon
        </div>
      </div>
    </div>
  </a-modal>
</template>

<script setup>
import { computed, ref, onMounted, watch } from "vue";
import { 
  UserOutlined, 
  EditOutlined, 
  SafetyCertificateOutlined,
  ClockCircleOutlined,
  TeamOutlined
} from "@ant-design/icons-vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { message } from "ant-design-vue";

const page = usePage();

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  user: {
    type: Object,
    default: null,
  },
});

// Emits
const emit = defineEmits(["close", "edit", "userUpdated"]);

// Current user
const currentUser = computed(() => page.props.auth.user?.data);

// Reactive variables for supervisor assignment
const availableSupervisors = ref([]);
const selectedSupervisorId = ref(null);
const loadingSupervisors = ref(false);
const assigningSupervisor = ref(false);
const removingSupervisor = ref(false);

// Can edit check - only admin and super admin can access full edit modal
const canEdit = computed(() => {
  if (!props.user) return false;
  
  const currentUserRoles = currentUser.value?.roles?.map(role => role.name.toLowerCase()) || [];
  const targetUserRoles = props.user.roles?.map(role => role.name.toLowerCase()) || [];
  
  // super admin can edit anyone except other super admins
  if (currentUserRoles.includes('super admin')) {
    return !targetUserRoles.includes('super admin') || props.user.id === currentUser.value?.id;
  }
  
  // admin can edit users except super admins
  if (currentUserRoles.includes('admin')) {
    return !targetUserRoles.includes('super admin');
  }
  
  // managers and below cannot access full edit modal
  // they can only use supervisor assignment and non-sensitive field updates
  return false;
});

// Check if user is a cashier
const isCashier = computed(() => {
  if (!props.user || !props.user.roles) return false;
  return props.user.roles.some(role => role.name.toLowerCase() === 'cashier');
});

// Check if current user can assign supervisors
const canAssignSupervisor = computed(() => {
  if (!currentUser.value || !props.user) return false;
  
  const currentUserRoles = currentUser.value.roles?.map(role => role.name.toLowerCase()) || [];
  
  // Super admin, admin, and manager can assign supervisors to cashiers
  return (currentUserRoles.includes('super admin') || 
          currentUserRoles.includes('admin') || 
          currentUserRoles.includes('manager')) && 
         isCashier.value;
});

// Methods
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

const getRoleDescription = (roleName) => {
  const descriptions = {
    'Super Admin': 'Complete system access including user management, system settings, and all administrative functions.',
    'Admin': 'Administrative access with user management, reporting, and most system functions except critical settings.',
    'Manager': 'Operational management including sales oversight, reporting, inventory management, and staff coordination.',
    'Cashier': 'Front-line operations including sales processing, customer service, and basic inventory functions.',
  };
  return descriptions[roleName] || 'Standard user permissions with limited access.';
};

const formatDate = (date) => {
  if (!date) return "N/A";
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const getAccountAge = (createdAt) => {
  if (!createdAt) return 0;
  const created = new Date(createdAt);
  const now = new Date();
  const diffTime = Math.abs(now - created);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  return diffDays;
};

// Supervisor assignment methods
const loadAvailableSupervisors = async () => {
  if (!canAssignSupervisor.value) return;
  
  try {
    loadingSupervisors.value = true;
    const response = await axios.get('/supervisors/available');
    availableSupervisors.value = response.data.supervisors;
  } catch (error) {
    console.error('Error loading supervisors:', error);
    message.error('Failed to load available supervisors');
  } finally {
    loadingSupervisors.value = false;
  }
};

const assignSupervisor = async () => {
  if (!selectedSupervisorId.value || !props.user) return;
  
  try {
    assigningSupervisor.value = true;
    
    // Debug logging
    console.log('Assigning supervisor:', {
      cashier_id: props.user.id,
      supervisor_id: selectedSupervisorId.value,
      current_user: currentUser.value,
      url: `/users/${props.user.id}/assign-supervisor`
    });
    
    const response = await axios.post(`/users/${props.user.id}/assign-supervisor`, {
      supervisor_id: selectedSupervisorId.value
    });
    
    message.success('Supervisor assigned successfully');
    selectedSupervisorId.value = null;
    
    // Emit event to refresh user data
    emit('userUpdated', response.data.cashier);
    
  } catch (error) {
    console.error('Error assigning supervisor:', error);
    console.error('Error details:', {
      status: error.response?.status,
      statusText: error.response?.statusText,
      data: error.response?.data,
      headers: error.response?.headers
    });
    message.error(error.response?.data?.message || 'Failed to assign supervisor');
  } finally {
    assigningSupervisor.value = false;
  }
};

const removeSupervisor = async () => {
  if (!props.user) return;
  
  try {
    removingSupervisor.value = true;
    const response = await axios.delete(`/users/${props.user.id}/remove-supervisor`);
    
    message.success('Supervisor assignment removed successfully');
    
    // Emit event to refresh user data
    emit('userUpdated', response.data.cashier);
    
  } catch (error) {
    console.error('Error removing supervisor:', error);
    message.error(error.response?.data?.message || 'Failed to remove supervisor assignment');
  } finally {
    removingSupervisor.value = false;
  }
};

const filterSupervisorOption = (input, option) => {
  return option.children[0].children.toLowerCase().includes(input.toLowerCase());
};

// Watch for modal visibility to load supervisors
watch(() => props.visible, (newVisible) => {
  if (newVisible && canAssignSupervisor.value) {
    loadAvailableSupervisors();
  }
});

// Load supervisors when component mounts if modal is visible
onMounted(() => {
  if (props.visible && canAssignSupervisor.value) {
    loadAvailableSupervisors();
  }
});
</script>
