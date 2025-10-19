<script setup>
import { computed } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { IconEdit, IconArrowLeft, IconWorld, IconMapPin, IconCurrencyDollar, IconClock, IconUsers, IconCalendar } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

// Computed properties
const domain = computed(() => props.domain);

const formatDate = (dateString) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

// Methods
const handleEdit = () => {
    router.visit(route('domains.edit', domain.value.id));
};

const handleBack = () => {
    router.visit(route('domains.index'));
};
</script>

<template>
    <Head :title="`Domain: ${domain.name}`" />

    <AuthenticatedLayout>
        <div class="py-6">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <!-- Header Section -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <button
                                    @click="handleBack"
                                    class="p-2 text-gray-400 hover:text-gray-600 transition-colors"
                                >
                                    <IconArrowLeft class="h-5 w-5" />
                                </button>
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <IconWorld class="h-6 w-6 text-blue-600" />
                                    </div>
                                    <div>
                                        <h1 class="text-2xl font-bold text-gray-900">{{ domain.name }}</h1>
                                        <p class="text-gray-600">{{ domain.description || 'No description provided' }}</p>
                                    </div>
                                </div>
                            </div>
                            <button
                                @click="handleEdit"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                            >
                                <IconEdit class="h-4 w-4" />
                                Edit Domain
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Domain Details -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Information -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Basic Information -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-900 mb-4">Basic Information</h2>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-500 mb-1">Domain Name</label>
                                        <p class="text-lg font-semibold text-gray-900">{{ domain.name }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-500 mb-1">Domain Slug</label>
                                        <p class="font-mono text-lg text-gray-900">{{ domain.name_slug }}</p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-500 mb-1">Description</label>
                                        <p class="text-gray-900">{{ domain.description || 'No description provided' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Location & Settings -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-900 mb-4">Location & Settings</h2>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-500 mb-1">
                                            <IconMapPin class="inline h-4 w-4 mr-1" />
                                            Country
                                        </label>
                                        <p class="text-gray-900">{{ domain.country || 'Not specified' }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-500 mb-1">
                                            <IconCurrencyDollar class="inline h-4 w-4 mr-1" />
                                            Currency
                                        </label>
                                        <p class="text-gray-900">{{ domain.currency || 'Not specified' }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-500 mb-1">
                                            <IconClock class="inline h-4 w-4 mr-1" />
                                            Timezone
                                        </label>
                                        <p class="text-gray-900">{{ domain.timezone || 'Not specified' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Users -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-900 mb-4">
                                    <IconUsers class="inline h-5 w-5 mr-2" />
                                    Users ({{ domain.users?.length || 0 }})
                                </h2>
                                <div v-if="domain.users && domain.users.length > 0" class="space-y-3">
                                    <div
                                        v-for="user in domain.users"
                                        :key="user.id"
                                        class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg"
                                    >
                                        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                            <span class="text-sm font-semibold text-blue-600">
                                                {{ user.name.charAt(0).toUpperCase() }}
                                            </span>
                                        </div>
                                        <div class="flex-1">
                                            <p class="font-medium text-gray-900">{{ user.name }}</p>
                                            <p class="text-sm text-gray-500">{{ user.email }}</p>
                                        </div>
                                        <div class="text-right">
                                            <span v-if="user.is_super_user" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                Super User
                                            </span>
                                            <span v-else class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                User
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="text-center py-8 text-gray-500">
                                    <IconUsers class="h-12 w-12 mx-auto mb-2 text-gray-300" />
                                    <p>No users assigned to this domain</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Status Card -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Status</h3>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-gray-500">Status</span>
                                        <a-tag :color="domain.is_active ? 'green' : 'red'">
                                            {{ domain.is_active ? 'Active' : 'Inactive' }}
                                        </a-tag>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-gray-500">Created</span>
                                        <span class="text-sm text-gray-900">{{ formatDate(domain.created_at) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-gray-500">Updated</span>
                                        <span class="text-sm text-gray-900">{{ formatDate(domain.updated_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                                <div class="space-y-3">
                                    <button
                                        @click="handleEdit"
                                        class="w-full flex items-center gap-3 p-3 text-left text-gray-700 hover:bg-gray-50 rounded-lg transition-colors"
                                    >
                                        <IconEdit class="h-4 w-4" />
                                        Edit Domain
                                    </button>
                                    <button
                                        @click="handleBack"
                                        class="w-full flex items-center gap-3 p-3 text-left text-gray-700 hover:bg-gray-50 rounded-lg transition-colors"
                                    >
                                        <IconArrowLeft class="h-4 w-4" />
                                        Back to Domains
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Domain URL -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Domain URL</h3>
                                <div class="p-3 bg-gray-50 rounded-lg">
                                    <p class="font-mono text-sm text-gray-600">
                                        {{ domain.name_slug }}.techiko-pos.com
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
