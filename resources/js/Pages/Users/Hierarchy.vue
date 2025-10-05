<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { 
  IconUser, 
  IconUsers, 
  IconUserCheck, 
  IconShield, 
  IconSettings,
  IconEye,
  IconHierarchy,
  IconArrowUp,
  IconArrowDown,
  IconMail,
  IconPhone,
  IconCalendar,
  IconChevronRight,
  IconChevronDown
} from "@tabler/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { usePermissions } from "@/Composables/usePermissions";
import { Vue3OrgChart } from 'vue3-org-chart';
import 'vue3-org-chart/dist/style.css';

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import CascadingAssignment from "@/Components/CascadingAssignment.vue";

const { spinning } = useGlobalVariables();
const { canManageUsers, isSuperUser } = usePermissions();

const props = defineProps({
  users: Array,
  hierarchy: Object,
});

// State
const selectedUser = ref(null);
const showSupervisorModal = ref(false);
const availableSupervisors = ref([]);
const autoAssignLoading = ref(false);

// Computed properties
const topLevelUsers = computed(() => {
  return props.users?.filter(user => !user.supervisor_id) || [];
});

const getUserRole = (user) => {
  return user.roles?.[0]?.name || 'No Role';
};

const getRoleColor = (roleName) => {
  const roleColors = {
    'super admin': 'red',
    'admin': 'orange', 
    'manager': 'blue',
    'supervisor': 'purple',
    'cashier': 'green',
  };
  return roleColors[roleName] || 'default';
};

const getRoleIcon = (roleName) => {
  const roleIcons = {
    'super admin': IconShield,
    'admin': IconSettings,
    'manager': IconUsers,
    'supervisor': IconUserCheck,
    'cashier': IconUser,
  };
  return roleIcons[roleName] || IconUser;
};

const getSubordinates = (userId) => {
  return props.users?.filter(user => user.supervisor_id === userId) || [];
};

const getUserStats = (user) => {
  const subordinates = getSubordinates(user.id);
  return {
    subordinatesCount: subordinates.length,
  };
};

// Transform users data for vue3-org-chart
const orgChartData = computed(() => {
  if (!props.users) return null;
  
  const transformUser = (user) => {
    const subordinates = getSubordinates(user.id);
    return {
      id: user.id,
      name: user.name,
      role: getUserRole(user),
      email: user.email,
      status: user.status,
      supervisor: user.supervisor?.name || null,
      subordinatesCount: subordinates.length,
      children: subordinates.map(transformUser)
    };
  };
  
  return topLevelUsers.value.map(transformUser);
});

// Get users who can be supervisors (level-based)
const getAvailableSupervisors = computed(() => {
  if (!props.users) return [];
  
  return props.users.filter(user => {
    const userRole = getUserRole(user);
    // Level-based rule: only management roles can supervise others
    return ['super admin', 'admin', 'manager', 'supervisor'].includes(userRole);
  });
});

// Get users without supervisors (can be assigned)
const usersWithoutSupervisors = computed(() => {
  if (!props.users) return [];
  
  return props.users.filter(user => !user.supervisor_id);
});

// Methods
const handleBackToUsers = () => {
  router.visit(route('users.index'));
};

const handleViewUser = (user) => {
  router.visit(route('users.show', user.id));
};

const handleEditUser = (user) => {
  router.visit(route('users.edit', user.id));
};

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  return new Date(dateString).toLocaleDateString();
};

const getStatusColor = (status) => {
  return status === 'active' ? 'green' : 'red';
};

const getStatusText = (status) => {
  return status === 'active' ? 'Active' : 'Inactive';
};

// Level-based supervisor assignment methods
const assignSupervisor = async (userId, supervisorId) => {
  try {
    await router.post(route('users.assign-supervisor', userId), {
      supervisor_id: supervisorId
    }, {
      onSuccess: () => {
        // Refresh to show updated hierarchy
        router.reload();
      }
    });
  } catch (error) {
    console.error('Error assigning supervisor:', error);
  }
};

const removeSupervisor = async (userId) => {
  try {
    await router.delete(route('users.remove-supervisor', userId), {
      onSuccess: () => {
        // Refresh to show updated hierarchy
        router.reload();
      }
    });
  } catch (error) {
    console.error('Error removing supervisor:', error);
  }
};

// Auto-assign supervisors based on hierarchy levels
const autoAssignSupervisors = async () => {
  autoAssignLoading.value = true;
  try {
    await router.post(route('supervisors.auto-assign'), {}, {
      onSuccess: (page) => {
        // Show success message with results
        if (page.props.flash?.message) {
          console.log('Auto-assignment completed:', page.props.flash.message);
        }
        // Refresh to show updated hierarchy
        router.reload();
      }
    });
  } catch (error) {
    console.error('Error auto-assigning supervisors:', error);
  } finally {
    autoAssignLoading.value = false;
  }
};

// Get available supervisors for a specific user
const getSupervisorsForUser = async (userId) => {
  try {
    const response = await fetch(route('supervisors.available-for-user', userId));
    const data = await response.json();
    availableSupervisors.value = data.supervisors;
    return data.supervisors;
  } catch (error) {
    console.error('Error fetching available supervisors:', error);
    return [];
  }
};

// Show supervisor assignment modal
const showAssignSupervisorModal = async (user) => {
  selectedUser.value = user;
  await getSupervisorsForUser(user.id);
  showSupervisorModal.value = true;
};

// Close supervisor assignment modal
const closeSupervisorModal = () => {
  showSupervisorModal.value = false;
  selectedUser.value = null;
  availableSupervisors.value = [];
};
</script>

<template>
  <Head title="User Hierarchy" />

  <AuthenticatedLayout>
    <ContentHeader title="User Hierarchy" />

    <div class="max-w-7xl mx-auto p-6 space-y-6">
      <!-- Debug Information -->
      <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
        <h4 class="text-sm font-medium text-yellow-800 mb-2">Debug Information:</h4>
        <div class="text-sm text-yellow-700 grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <p><strong>Total Users:</strong> {{ users?.length || 0 }}</p>
          </div>
          <div>
            <p><strong>Top Level Users:</strong> {{ topLevelUsers.length }}</p>
          </div>
          <div>
            <p><strong>With Supervisors:</strong> {{ users?.filter(u => u.supervisor_id).length || 0 }}</p>
          </div>
          <div>
            <p><strong>Org Chart Data:</strong> {{ orgChartData ? orgChartData.length : 'null' }}</p>
          </div>
        </div>
      </div>

      <!-- Header Section -->
      <div class="bg-white rounded-lg shadow-sm border p-6">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-4">
            <div class="p-3 bg-blue-100 rounded-lg">
              <IconHierarchy class="w-8 h-8 text-blue-600" />
            </div>
            <div>
              <h2 class="text-2xl font-bold text-gray-900">User Hierarchy</h2>
              <p class="text-gray-600">Organizational structure showing supervisor-subordinate relationships</p>
            </div>
          </div>
          <div class="flex items-center space-x-3">
            <a-button 
              v-if="isSuperUser || canManageUsers"
              @click="autoAssignSupervisors" 
              :loading="autoAssignLoading"
              type="primary"
              class="flex items-center"
            >
              <template #icon>
                <IconUserCheck />
              </template>
              Auto-Assign Supervisors
            </a-button>
            <a-button @click="handleBackToUsers" class="flex items-center">
              <template #icon>
                <IconArrowUp />
              </template>
              Back to Users
            </a-button>
          </div>
        </div>
      </div>

      <!-- Hierarchy Stats -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow-sm border p-4">
          <div class="flex items-center space-x-3">
            <div class="p-2 bg-blue-100 rounded-lg">
              <IconUsers class="w-6 h-6 text-blue-600" />
            </div>
            <div>
              <div class="text-2xl font-bold text-gray-900">{{ users?.length || 0 }}</div>
              <div class="text-sm text-gray-600">Total Users</div>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-sm border p-4">
          <div class="flex items-center space-x-3">
            <div class="p-2 bg-green-100 rounded-lg">
              <IconUserCheck class="w-6 h-6 text-green-600" />
            </div>
            <div>
              <div class="text-2xl font-bold text-gray-900">{{ users?.filter(u => u.status === 'active').length || 0 }}</div>
              <div class="text-sm text-gray-600">Active Users</div>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-sm border p-4">
          <div class="flex items-center space-x-3">
            <div class="p-2 bg-purple-100 rounded-lg">
              <IconShield class="w-6 h-6 text-purple-600" />
            </div>
            <div>
              <div class="text-2xl font-bold text-gray-900">{{ topLevelUsers.length }}</div>
              <div class="text-sm text-gray-600">Top Level Users</div>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-sm border p-4">
          <div class="flex items-center space-x-3">
            <div class="p-2 bg-orange-100 rounded-lg">
              <IconUser class="w-6 h-6 text-orange-600" />
            </div>
            <div>
              <div class="text-2xl font-bold text-gray-900">{{ usersWithoutSupervisors.length }}</div>
              <div class="text-sm text-gray-600">Without Supervisor</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Simple User List (Fallback) -->
      <div class="bg-white rounded-lg shadow-sm border p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-6">All Users (Simple View)</h3>
        
        <div v-if="!users || users.length === 0" class="text-center py-8">
          <IconUsers class="mx-auto h-12 w-12 text-gray-400" />
          <h3 class="mt-2 text-sm font-medium text-gray-900">No users found</h3>
          <p class="mt-1 text-sm text-gray-500">
            There are no users to display.
          </p>
        </div>

        <div v-else class="space-y-4">
          <div 
            v-for="user in users" 
            :key="user.id"
            class="border rounded-lg p-4 hover:bg-gray-50"
          >
            <div class="flex items-center justify-between">
              <div class="flex items-center space-x-4">
                <div class="flex-shrink-0">
                  <component 
                    :is="getRoleIcon(getUserRole(user))" 
                    class="w-8 h-8"
                    :class="`text-${getRoleColor(getUserRole(user))}-600`"
                  />
                </div>
                <div>
                  <h4 class="text-lg font-medium text-gray-900">{{ user.name }}</h4>
                  <p class="text-sm text-gray-500">{{ getUserRole(user) }}</p>
                  <p class="text-sm text-gray-500">{{ user.email }}</p>
                  <p v-if="user.supervisor" class="text-xs text-gray-400">
                    Reports to: {{ user.supervisor.name }}
                  </p>
                  <p v-else class="text-xs text-gray-400">
                    No supervisor assigned
                  </p>
                </div>
              </div>
              <div class="flex space-x-2">
                <a-button size="small" @click="handleViewUser({id: user.id})">
                  <template #icon>
                    <IconEye />
                  </template>
                  View
                </a-button>
                <a-button 
                  v-if="canManageUsers || isSuperUser"
                  size="small" 
                  type="primary" 
                  @click="handleEditUser({id: user.id})"
                >
                  <template #icon>
                    <IconSettings />
                  </template>
                  Edit
                </a-button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Hierarchy Tree (Advanced) -->
      <div class="bg-white rounded-lg shadow-sm border p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-6">Organizational Structure (Advanced)</h3>
        
        <div v-if="orgChartData && orgChartData.length > 0" class="org-chart-container">
          <Vue3OrgChart :data="orgChartData">
            <template #node="{item, children, open, toggleChildren}">
              <div 
                class="user-node-card"
                :class="`role-${item.role.replace(' ', '-')}`"
              >
                <div class="user-avatar">
                  <component 
                    :is="getRoleIcon(item.role)" 
                    class="w-8 h-8"
                    :class="`text-${getRoleColor(item.role)}-600`"
                  />
                </div>
                
                <div class="user-info">
                  <h4 class="user-name">{{ item.name }}</h4>
                  <p class="user-role">{{ item.role }}</p>
                  <p class="user-email">{{ item.email }}</p>
                  
                  <div v-if="item.supervisor" class="supervisor-info">
                    <span class="text-xs text-gray-500">
                      Reports to: <strong>{{ item.supervisor }}</strong>
                    </span>
                  </div>
                  
                  <div class="user-stats">
                    <span class="stat-item">
                      <IconUsers class="w-4 h-4" />
                      {{ item.subordinatesCount }} direct reports
                    </span>
                  </div>
                  
                  <div class="user-status">
                    <a-tag 
                      :color="getStatusColor(item.status)" 
                      size="small"
                    >
                      {{ getStatusText(item.status) }}
                    </a-tag>
                  </div>
                </div>
                
                <div class="user-actions">
                  <a-button 
                    size="small"
                    @click="handleViewUser({id: item.id})"
                    class="action-btn"
                  >
                    <template #icon>
                      <IconEye />
                    </template>
                    View
                  </a-button>
                  <a-button 
                    v-if="canManageUsers || isSuperUser"
                    size="small"
                    type="primary"
                    @click="handleEditUser({id: item.id})"
                    class="action-btn"
                  >
                    <template #icon>
                      <IconSettings />
                    </template>
                    Edit
                  </a-button>
                  
                  <!-- Supervisor Assignment Actions -->
                  <a-dropdown v-if="canManageUsers || isSuperUser" placement="bottomRight">
                    <a-button size="small" class="action-btn">
                      <template #icon>
                        <IconUserCheck />
                      </template>
                      Assign
                    </a-button>
                    <template #overlay>
                      <a-menu>
                        <a-menu-item 
                          v-for="supervisor in availableSupervisors.filter(s => s.id !== item.id)"
                          :key="supervisor.id"
                          @click="assignSupervisor(item.id, supervisor.id)"
                        >
                          Assign to {{ supervisor.name }}
                        </a-menu-item>
                        <a-menu-item 
                          v-if="item.supervisor"
                          @click="removeSupervisor(item.id)"
                          class="text-red-600"
                        >
                          Remove Supervisor
                        </a-menu-item>
                      </a-menu>
                    </template>
                  </a-dropdown>
                </div>
                
                <button 
                  v-if="children.length > 0"
                  @click="toggleChildren"
                  class="toggle-btn"
                >
                  {{ open ? '−' : '+' }}
                </button>
              </div>
            </template>
          </Vue3OrgChart>
        </div>
        
        <div v-else class="text-center py-12">
          <IconUsers class="w-16 h-16 text-gray-400 mx-auto mb-4" />
          <h3 class="text-lg font-medium text-gray-900 mb-2">No Users Found</h3>
          <p class="text-gray-600">There are no users in the hierarchy to display.</p>
        </div>
      </div>

      <!-- Users Without Supervisors -->
      <div v-if="usersWithoutSupervisors.length > 0" class="bg-white rounded-lg shadow-sm border p-6 mt-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Users Without Supervisors</h3>
        <p class="text-sm text-gray-600 mb-4">These users are not currently assigned to any supervisor in the hierarchy.</p>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div 
            v-for="user in usersWithoutSupervisors"
            :key="user.id"
            class="border rounded-lg p-4 hover:shadow-md transition-shadow"
          >
            <div class="flex items-center space-x-3 mb-3">
              <div 
                class="p-2 rounded-lg"
                :class="`bg-${getRoleColor(getUserRole(user))}-100`"
              >
                <component 
                  :is="getRoleIcon(getUserRole(user))" 
                  class="w-6 h-6"
                  :class="`text-${getRoleColor(getUserRole(user))}-600`"
                />
              </div>
              <div>
                <h4 class="font-semibold text-gray-900">{{ user.name }}</h4>
                <p class="text-sm text-gray-600">{{ getUserRole(user) }}</p>
              </div>
            </div>
            
            <div class="flex items-center justify-between">
              <a-tag 
                :color="getStatusColor(user.status)" 
                size="small"
              >
                {{ getStatusText(user.status) }}
              </a-tag>
              
              <a-dropdown v-if="canManageUsers || isSuperUser" placement="bottomRight">
                <a-button size="small">
                  <template #icon>
                    <IconUserCheck />
                  </template>
                  Assign Supervisor
                </a-button>
                <template #overlay>
                  <a-menu>
                    <a-menu-item 
                      v-for="supervisor in availableSupervisors.filter(s => s.id !== user.id)"
                      :key="supervisor.id"
                      @click="assignSupervisor(user.id, supervisor.id)"
                    >
                      Assign to {{ supervisor.name }}
                    </a-menu-item>
                  </a-menu>
                </template>
              </a-dropdown>
            </div>
          </div>
        </div>
      </div>

      <!-- Cascading Assignment -->
      <CascadingAssignment :current-user="users?.find(u => u.id === $page.props.auth.user?.id)" />

      <!-- Quick Actions -->
      <div class="bg-white rounded-lg shadow-sm border p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
        
        <div class="flex flex-wrap gap-4">
          <a-button 
            @click="router.visit(route('users.index'))"
            class="flex items-center"
          >
            <template #icon>
              <IconUsers />
            </template>
            View All Users
          </a-button>
          
          <a-button 
            v-if="canManageUsers || isSuperUser"
            @click="router.visit(route('users.create'))"
            type="primary"
            class="flex items-center"
          >
            <template #icon>
              <IconUser />
            </template>
            Add New User
          </a-button>
          
          <a-button 
            @click="router.visit(route('roles.permission-matrix'))"
            class="flex items-center"
          >
            <template #icon>
              <IconShield />
            </template>
            Permission Matrix
          </a-button>
        </div>
      </div>
    </div>

    <!-- Supervisor Assignment Modal -->
    <a-modal
      v-model:open="showSupervisorModal"
      title="Assign Supervisor"
      @ok="closeSupervisorModal"
      @cancel="closeSupervisorModal"
      :footer="null"
    >
      <div v-if="selectedUser" class="space-y-4">
        <div class="bg-gray-50 p-4 rounded-lg">
          <h4 class="font-semibold text-gray-900 mb-2">Assigning Supervisor For:</h4>
          <div class="flex items-center space-x-3">
            <component 
              :is="getRoleIcon(getUserRole(selectedUser))" 
              class="w-8 h-8"
              :class="`text-${getRoleColor(getUserRole(selectedUser))}-600`"
            />
            <div>
              <p class="font-medium text-gray-900">{{ selectedUser.name }}</p>
              <p class="text-sm text-gray-600">{{ getUserRole(selectedUser) }}</p>
            </div>
          </div>
        </div>

        <div v-if="availableSupervisors.length > 0">
          <h4 class="font-semibold text-gray-900 mb-3">Available Supervisors:</h4>
          <div class="space-y-2 max-h-60 overflow-y-auto">
            <div 
              v-for="supervisor in availableSupervisors"
              :key="supervisor.id"
              class="flex items-center justify-between p-3 border rounded-lg hover:bg-gray-50"
            >
              <div class="flex items-center space-x-3">
                <component 
                  :is="getRoleIcon(getUserRole(supervisor))" 
                  class="w-6 h-6"
                  :class="`text-${getRoleColor(getUserRole(supervisor))}-600`"
                />
                <div>
                  <p class="font-medium text-gray-900">{{ supervisor.name }}</p>
                  <p class="text-sm text-gray-600">{{ getUserRole(supervisor) }}</p>
                </div>
              </div>
              <a-button 
                size="small"
                type="primary"
                @click="assignSupervisor(selectedUser.id, supervisor.id); closeSupervisorModal()"
              >
                Assign
              </a-button>
            </div>
          </div>
        </div>

        <div v-else class="text-center py-8">
          <IconUserCheck class="w-12 h-12 text-gray-400 mx-auto mb-2" />
          <p class="text-gray-600">No available supervisors found for this user.</p>
          <p class="text-sm text-gray-500">This may be due to hierarchy level restrictions.</p>
        </div>

        <div class="flex justify-end space-x-2 pt-4 border-t">
          <a-button @click="closeSupervisorModal">Cancel</a-button>
        </div>
      </div>
    </a-modal>
  </AuthenticatedLayout>
</template>


<style scoped>
/* Org Chart Container */
.org-chart-container {
  min-height: 400px;
  overflow: auto;
}

/* User Node Card */
.user-node-card {
  @apply bg-white border-2 rounded-lg p-4 shadow-sm hover:shadow-md transition-all duration-300;
  @apply border-gray-200 hover:border-blue-300;
  min-width: 280px;
  max-width: 320px;
  position: relative;
}

.user-node-card:hover {
  transform: translateY(-2px);
}

/* Role-specific styling */
.user-node-card.role-super-admin {
  @apply border-red-200 hover:border-red-300;
}

.user-node-card.role-admin {
  @apply border-orange-200 hover:border-orange-300;
}

.user-node-card.role-manager {
  @apply border-blue-200 hover:border-blue-300;
}

.user-node-card.role-supervisor {
  @apply border-purple-200 hover:border-purple-300;
}

.user-node-card.role-cashier {
  @apply border-green-200 hover:border-green-300;
}

/* User Avatar */
.user-avatar {
  @apply flex items-center justify-center w-12 h-12 rounded-full mb-3;
  @apply bg-gray-100;
}

/* User Info */
.user-info {
  @apply flex-1;
}

.user-name {
  @apply text-lg font-semibold text-gray-900 mb-1;
}

.user-role {
  @apply text-sm font-medium text-gray-600 mb-1;
}

.user-email {
  @apply text-xs text-gray-500 mb-2;
}

.user-stats {
  @apply flex items-center space-x-3 mb-2;
}

.stat-item {
  @apply flex items-center space-x-1 text-xs text-gray-500;
}

.user-status {
  @apply mb-3;
}

.supervisor-info {
  @apply mb-2 p-2 bg-gray-50 rounded text-xs;
}

/* User Actions */
.user-actions {
  @apply flex items-center space-x-2 mb-3;
}

.action-btn {
  @apply text-xs;
}

/* Toggle Button */
.toggle-btn {
  @apply absolute top-2 right-2 w-6 h-6 rounded-full;
  @apply bg-blue-500 text-white text-sm font-bold;
  @apply hover:bg-blue-600 transition-colors;
  @apply flex items-center justify-center;
}

/* Vue3 Org Chart Custom Variables */
:root {
  --vue3-org-chart-container-height: 70vh;
  --vue3-org-chart-line-top: 1rem;
  --vue3-org-chart-line-bottom: 1rem;
  --vue3-org-chart-node-space-x: 2rem;
  --vue3-org-chart-line-color: #3b82f6;
}

/* Responsive Design */
@media (max-width: 768px) {
  .user-node-card {
    min-width: 240px;
    max-width: 280px;
  }
  
  .user-name {
    @apply text-base;
  }
  
  .user-role {
    @apply text-xs;
  }
  
  .user-email {
    @apply text-xs;
  }
}
</style>
