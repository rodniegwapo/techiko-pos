<script setup>
import { Head, router } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import { 
  IconUser, 
  IconMail, 
  IconShield, 
  IconArrowLeft,
  IconEdit,
  IconUsers,
  IconUserCheck
} from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const props = defineProps({
  user: Object,
});

// Role helpers
const currentRoleName = computed(() => (props.user.roles?.[0]?.name || '').toLowerCase());
const assignLabel = computed(() => {
  const map = {
    'cashier': 'Assign Supervisor',
    'supervisor': 'Assign Manager',
    'manager': 'Assign Admin',
    'admin': 'Assign Super Admin',
  };
  return map[currentRoleName.value] || 'Assign Supervisor';
});

// Assign supervisor modal state
const showAssignModal = ref(false);
const availableSupervisors = ref([]);
const loadingSupervisors = ref(false);
const assigning = ref(false);

const openAssignModal = async () => {
  loadingSupervisors.value = true;
  showAssignModal.value = true;
  try {
    const res = await fetch(route('supervisors.available-for-user', props.user.id));
    const data = await res.json();
    availableSupervisors.value = data?.supervisors || [];
  } catch (e) {
    availableSupervisors.value = [];
  } finally {
    loadingSupervisors.value = false;
  }
};

const assignSupervisor = async (supervisorId) => {
  assigning.value = true;
  try {
    await router.post(route('users.assign-supervisor', props.user.id), {
      supervisor_id: supervisorId,
    }, {
      preserveScroll: true,
      onFinish: () => {
        assigning.value = false;
        showAssignModal.value = false;
      },
    });
  } catch (e) {
    assigning.value = false;
  }
};

const handleBack = () => {
  router.visit(route('users.hierarchy'));
};

const handleEdit = () => {
  router.visit(route('users.edit', props.user.id));
};

const getRoleColor = (roleName) => {
  const roleColors = {
    'super admin': 'red',
    'admin': 'blue',
    'manager': 'green',
    'supervisor': 'yellow',
    'cashier': 'gray',
  };
  return roleColors[roleName] || 'gray';
};

const getRoleIcon = (roleName) => {
  const roleIcons = {
    'super admin': IconShield,
    'admin': IconShield,
    'manager': IconUserCheck,
    'supervisor': IconUsers,
    'cashier': IconUser,
  };
  return roleIcons[roleName] || IconUser;
};
</script>

<template>
  <Head :title="`User: ${user.name}`" />
  
  <AuthenticatedLayout>
    <ContentHeader :title="`User: ${user.name}`" />
    
    <div class="py-6">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <!-- Back Button -->
        <div class="mb-6">
          <a-button @click="handleBack" class="flex items-center">
            <template #icon>
              <IconArrowLeft />
            </template>
            Back to Hierarchy
          </a-button>
        </div>

        <!-- User Details Card -->
        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="px-4 py-5 sm:p-6">
            <div class="flex items-center justify-between mb-6">
              <div class="flex items-center space-x-4">
                <div class="flex-shrink-0">
                  <component 
                    :is="getRoleIcon(user.roles?.[0]?.name || 'No Role')" 
                    class="w-16 h-16"
                    :class="`text-${getRoleColor(user.roles?.[0]?.name || 'No Role')}-600`"
                  />
                </div>
                <div>
                  <h1 class="text-3xl font-bold text-gray-900">{{ user.name }}</h1>
                  <p class="text-lg text-gray-600">{{ user.roles?.[0]?.name || 'No Role' }}</p>
                  <p class="text-sm text-gray-500">{{ user.email }}</p>
                </div>
              </div>
              <div class="flex space-x-3">
                <a-button type="primary" @click="handleEdit">
                  <template #icon>
                    <IconEdit />
                  </template>
                  Edit User
                </a-button>
                <a-button 
                  v-if="availableSupervisors !== null"
                  @click="openAssignModal"
                >
                  <template #icon>
                    <IconUserCheck />
                  </template>
                  {{ assignLabel }}
                </a-button>
              </div>
            </div>

            <!-- User Information Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Basic Information -->
              <div class="space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Basic Information</h3>
                <div class="space-y-3">
                  <div class="flex items-center space-x-3">
                    <IconUser class="w-5 h-5 text-gray-400" />
                    <div>
                      <p class="text-sm font-medium text-gray-500">Name</p>
                      <p class="text-sm text-gray-900">{{ user.name }}</p>
                    </div>
                  </div>
                  <div class="flex items-center space-x-3">
                    <IconMail class="w-5 h-5 text-gray-400" />
                    <div>
                      <p class="text-sm font-medium text-gray-500">Email</p>
                      <p class="text-sm text-gray-900">{{ user.email }}</p>
                    </div>
                  </div>
                  <div class="flex items-center space-x-3">
                    <IconShield class="w-5 h-5 text-gray-400" />
                    <div>
                      <p class="text-sm font-medium text-gray-500">Role</p>
                      <p class="text-sm text-gray-900">{{ user.roles?.[0]?.name || 'No Role' }}</p>
                    </div>
                  </div>
                  <div class="flex items-center space-x-3">
                    <div class="w-5 h-5 flex items-center justify-center">
                      <div 
                        class="w-3 h-3 rounded-full"
                        :class="user.status === 'active' ? 'bg-green-500' : 'bg-red-500'"
                      ></div>
                    </div>
                    <div>
                      <p class="text-sm font-medium text-gray-500">Status</p>
                      <p class="text-sm text-gray-900 capitalize">{{ user.status }}</p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Hierarchy Information -->
              <div class="space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Hierarchy Information</h3>
                <div class="space-y-3">
                  <div v-if="user.supervisor" class="flex items-center space-x-3">
                    <IconUserCheck class="w-5 h-5 text-gray-400" />
                    <div>
                      <p class="text-sm font-medium text-gray-500">Supervisor</p>
                      <p class="text-sm text-gray-900">{{ user.supervisor.name }}</p>
                    </div>
                  </div>
                  <div v-else class="flex items-center space-x-3">
                    <IconUserCheck class="w-5 h-5 text-gray-400" />
                    <div>
                      <p class="text-sm font-medium text-gray-500">Supervisor</p>
                      <p class="text-sm text-gray-500">No supervisor assigned</p>
                    </div>
                  </div>
                  <div class="flex items-center space-x-3">
                    <IconUsers class="w-5 h-5 text-gray-400" />
                    <div>
                      <p class="text-sm font-medium text-gray-500">Direct Reports</p>
                      <p class="text-sm text-gray-900">{{ user.subordinates?.length || 0 }} subordinates</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Subordinates List -->
            <div v-if="user.subordinates && user.subordinates.length > 0" class="mt-8">
              <h3 class="text-lg font-medium text-gray-900 mb-4">Direct Reports</h3>
              <div class="space-y-3">
                <div 
                  v-for="subordinate in user.subordinates" 
                  :key="subordinate.id"
                  class="border rounded-lg p-4 hover:bg-gray-50"
                >
                  <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                      <component 
                        :is="getRoleIcon(subordinate.roles?.[0]?.name || 'No Role')" 
                        class="w-8 h-8"
                        :class="`text-${getRoleColor(subordinate.roles?.[0]?.name || 'No Role')}-600`"
                      />
                      <div>
                        <h4 class="text-sm font-medium text-gray-900">{{ subordinate.name }}</h4>
                        <p class="text-sm text-gray-500">{{ subordinate.roles?.[0]?.name || 'No Role' }}</p>
                        <p class="text-xs text-gray-400">{{ subordinate.email }}</p>
                      </div>
                    </div>
                    <div class="flex space-x-2">
                      <a-button size="small" @click="router.visit(route('users.show', subordinate.id))">
                        View
                      </a-button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<template #modals>
  <a-modal
    v-model:open="showAssignModal"
    :title="assignLabel"
    :footer="null"
    @cancel="() => { showAssignModal = false }"
  >
    <div v-if="loadingSupervisors" class="py-6 text-center">
      <a-spin />
    </div>
    <div v-else>
      <div v-if="availableSupervisors.length === 0" class="text-gray-500 py-4">
        No eligible users found for this assignment.
      </div>
      <div v-else class="space-y-2 max-h-80 overflow-auto">
        <div
          v-for="s in availableSupervisors"
          :key="s.id"
          class="flex items-center justify-between p-3 border rounded"
        >
          <div>
            <div class="font-medium">{{ s.name }}</div>
            <div class="text-xs text-gray-500">{{ s.email }}</div>
          </div>
          <a-button size="small" type="primary" :loading="assigning" @click="assignSupervisor(s.id)">
            Assign
          </a-button>
        </div>
      </div>
    </div>
  </a-modal>
</template>
