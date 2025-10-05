<script setup>
import { ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { 
  IconUser, 
  IconMail, 
  IconShield, 
  IconArrowLeft,
  IconDeviceFloppy
} from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

const props = defineProps({
  user: Object,
  roles: Array,
});

const form = useForm({
  name: props.user.name,
  email: props.user.email,
  role_id: props.user.roles?.[0]?.id || null,
  status: props.user.status || 'active',
});

const handleBack = () => {
  router.visit(route('users.hierarchy'));
};

const handleSubmit = () => {
  form.put(route('api.users.update', props.user.id), {
    onSuccess: () => {
      router.visit(route('users.show', props.user.id));
    }
  });
};
</script>

<template>
  <Head :title="`Edit User: ${user.name}`" />
  
  <AuthenticatedLayout>
    <ContentHeader :title="`Edit User: ${user.name}`" />
    
    <div class="py-6">
      <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <!-- Back Button -->
        <div class="mb-6">
          <a-button @click="handleBack" class="flex items-center">
            <template #icon>
              <IconArrowLeft />
            </template>
            Back to Hierarchy
          </a-button>
        </div>

        <!-- Edit Form -->
        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="px-4 py-5 sm:p-6">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Edit User</h2>
            
            <form @submit.prevent="handleSubmit" class="space-y-6">
              <!-- Name Field -->
              <div>
                <label for="name" class="block text-sm font-medium text-gray-700">
                  Name
                </label>
                <div class="mt-1 relative rounded-md shadow-sm">
                  <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <IconUser class="h-5 w-5 text-gray-400" />
                  </div>
                  <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md"
                    :class="{ 'border-red-300': form.errors.name }"
                    placeholder="Enter user name"
                  />
                </div>
                <p v-if="form.errors.name" class="mt-2 text-sm text-red-600">
                  {{ form.errors.name }}
                </p>
              </div>

              <!-- Email Field -->
              <div>
                <label for="email" class="block text-sm font-medium text-gray-700">
                  Email
                </label>
                <div class="mt-1 relative rounded-md shadow-sm">
                  <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <IconMail class="h-5 w-5 text-gray-400" />
                  </div>
                  <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md"
                    :class="{ 'border-red-300': form.errors.email }"
                    placeholder="Enter email address"
                  />
                </div>
                <p v-if="form.errors.email" class="mt-2 text-sm text-red-600">
                  {{ form.errors.email }}
                </p>
              </div>

              <!-- Role Field -->
              <div>
                <label for="role_id" class="block text-sm font-medium text-gray-700">
                  Role
                </label>
                <div class="mt-1 relative rounded-md shadow-sm">
                  <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <IconShield class="h-5 w-5 text-gray-400" />
                  </div>
                  <select
                    id="role_id"
                    v-model="form.role_id"
                    class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md"
                    :class="{ 'border-red-300': form.errors.role_id }"
                  >
                    <option value="">Select a role</option>
                    <option 
                      v-for="role in roles" 
                      :key="role.id" 
                      :value="role.id"
                    >
                      {{ role.name }}
                    </option>
                  </select>
                </div>
                <p v-if="form.errors.role_id" class="mt-2 text-sm text-red-600">
                  {{ form.errors.role_id }}
                </p>
              </div>

              <!-- Status Field -->
              <div>
                <label for="status" class="block text-sm font-medium text-gray-700">
                  Status
                </label>
                <div class="mt-1">
                  <select
                    id="status"
                    v-model="form.status"
                    class="focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"
                    :class="{ 'border-red-300': form.errors.status }"
                  >
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
                <p v-if="form.errors.status" class="mt-2 text-sm text-red-600">
                  {{ form.errors.status }}
                </p>
              </div>

              <!-- Form Actions -->
              <div class="flex items-center justify-end space-x-3 pt-6 border-t border-gray-200">
                <a-button @click="handleBack">
                  Cancel
                </a-button>
                <a-button 
                  type="primary" 
                  html-type="submit"
                  :loading="form.processing"
                  :disabled="form.processing"
                >
                  <template #icon>
                    <IconDeviceFloppy />
                  </template>
                  Save Changes
                </a-button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
