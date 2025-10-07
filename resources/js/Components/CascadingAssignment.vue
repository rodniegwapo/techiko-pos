<template>
  <div class="cascading-assignment">
    <div class="bg-white rounded-lg shadow-sm border p-6">
      <div class="flex items-center space-x-3 mb-4">
        <div class="p-2 bg-blue-100 rounded-lg">
          <IconUserCheck class="w-6 h-6 text-blue-600" />
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Cascading Assignment</h3>
          <p class="text-sm text-gray-600">Assign supervisors based on your hierarchy level</p>
        </div>
      </div>

      <div v-if="loading" class="text-center py-8">
        <a-spin size="large" />
        <p class="mt-2 text-gray-600">Loading assignment options...</p>
      </div>

      <div v-else-if="options.length === 0" class="text-center py-8">
        <IconUserCheck class="w-12 h-12 text-gray-400 mx-auto mb-2" />
        <h4 class="text-lg font-medium text-gray-900 mb-2">No Assignment Options</h4>
        <p class="text-gray-600">You are at the highest level or no supervisors are available for assignment.</p>
      </div>

      <div v-else class="space-y-4">
        <div v-for="option in options" :key="option.level" class="border rounded-lg p-4">
          <div class="flex items-center justify-between mb-3">
            <div>
              <h4 class="font-semibold text-gray-900">{{ option.description }}</h4>
              <p class="text-sm text-gray-600">Level {{ option.level }} - {{ option.role_name }}</p>
            </div>
            <a-tag :color="getLevelColor(option.level)" size="large">
              Level {{ option.level }}
            </a-tag>
          </div>

          <div v-if="option.users.length === 0" class="text-center py-4 text-gray-500">
            <p>No {{ option.role_name }}s available for assignment</p>
          </div>

          <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <div 
              v-for="user in option.users" 
              :key="user.id"
              class="border rounded-lg p-3 hover:shadow-md transition-shadow"
            >
              <div class="flex items-center space-x-3 mb-2">
                <component 
                  :is="getRoleIcon(getUserRole(user))" 
                  class="w-6 h-6"
                  :class="`text-${getRoleColor(getUserRole(user))}-600`"
                />
                <div class="flex-1">
                  <h5 class="font-medium text-gray-900">{{ user.name }}</h5>
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
                
                <a-button 
                  size="small"
                  type="primary"
                  @click="assignSupervisor(user)"
                  :loading="assigning === user.id"
                >
                  Assign as Supervisor
                </a-button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="userLevel" class="mt-6 p-4 bg-gray-50 rounded-lg">
        <div class="flex items-center space-x-2">
          <IconInfoCircle class="w-5 h-5 text-blue-600" />
          <span class="text-sm text-gray-700">
            <strong>Your Level:</strong> {{ userLevel }} - You can assign supervisors from Level {{ userLevel - 1 }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { IconUserCheck, IconInfoCircle, IconShield, IconUser } from '@tabler/icons-vue';

const props = defineProps({
  currentUser: Object,
});

// State
const loading = ref(true);
const options = ref([]);
const userLevel = ref(null);
const assigning = ref(null);

// Methods
const loadCascadingOptions = async () => {
  loading.value = true;
  try {
    const response = await fetch(route('supervisors.cascading-options'));
    const data = await response.json();
    
    if (data.success) {
      options.value = data.options;
      userLevel.value = data.user_level;
    }
  } catch (error) {
    console.error('Error loading cascading options:', error);
  } finally {
    loading.value = false;
  }
};

const assignSupervisor = async (supervisor) => {
  assigning.value = supervisor.id;
  try {
    const response = await fetch(route('supervisors.cascading-assign', supervisor.id), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
      }
    });
    
    const data = await response.json();
    
    if (data.success) {
      // Show success message
      console.log('Success:', data.message);
      // Reload the page to show updated hierarchy
      router.reload();
    } else {
      console.error('Error:', data.message);
    }
  } catch (error) {
    console.error('Error assigning supervisor:', error);
  } finally {
    assigning.value = null;
  }
};

// Helper methods
const getUserRole = (user) => {
  return user.roles?.[0]?.name || 'No Role';
};

const getRoleColor = (roleName) => {
  const roleColors = {
    'super admin': 'red',
    'admin': 'blue',
    'manager': 'green',
    'supervisor': 'purple',
    'cashier': 'orange',
    'default': 'gray'
  };
  return roleColors[roleName.toLowerCase()] || roleColors['default'];
};

const getRoleIcon = (roleName) => {
  const roleIcons = {
    'super admin': IconShield,
    'admin': IconUser,
    'manager': IconUser,
    'supervisor': IconUser,
    'cashier': IconUser,
    'default': IconUser
  };
  return roleIcons[roleName.toLowerCase()] || roleIcons['default'];
};

const getLevelColor = (level) => {
  const colors = {
    1: 'red',
    2: 'blue',
    3: 'green',
    4: 'purple',
    5: 'orange'
  };
  return colors[level] || 'default';
};

const getStatusColor = (status) => {
  return status === 'active' ? 'green' : 'red';
};

const getStatusText = (status) => {
  return status === 'active' ? 'Active' : 'Inactive';
};

// Lifecycle
onMounted(() => {
  loadCascadingOptions();
});
</script>

<style scoped>
.cascading-assignment {
  @apply w-full;
}
</style>
