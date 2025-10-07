<script setup>
import { computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { IconArrowLeft, IconShield, IconUsers, IconEdit } from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { usePermissions } from "@/Composables/usePermissions";

const props = defineProps({
    permission: Object,
    roles: Array,
});

const { canManageRoles, isSuperUser } = usePermissions();

// Computed
const canEdit = computed(() => canManageRoles.value || isSuperUser.value);

// Methods
const handleEdit = () => {
    router.visit(route("permissions.edit", props.permission.id));
};

const handleBack = () => {
    router.visit(route("permissions.index"));
};

const formatDate = (date) => {
    if (!date) return "-";
    return new Date(date).toLocaleDateString();
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Permission Details" />
        <ContentHeader class="mb-8" title="Permission Details" />

        <ContentLayout title="Permission Details">
            <div class="max-w-4xl mx-auto">
                <!-- Permission Info Card -->
                <a-card class="mb-6">
                    <template #title>
                        <div class="flex items-center gap-3">
                            <IconShield class="text-blue-500" size="24" />
                            <span>{{ permission.name }}</span>
                        </div>
                    </template>
                    <template #extra>
                        <div class="flex items-center gap-2">
                            <a-button @click="handleBack" size="small">
                                <template #icon>
                                    <IconArrowLeft />
                                </template>
                                Back
                            </a-button>
                            <a-button
                                v-if="canEdit"
                                @click="handleEdit"
                                type="primary"
                                size="small"
                            >
                                <template #icon>
                                    <IconEdit />
                                </template>
                                Edit
                            </a-button>
                        </div>
                    </template>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Basic Info -->
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Permission Name
                                </label>
                                <div class="flex items-center gap-2">
                                    <IconShield class="text-blue-500" size="16" />
                                    <span class="text-gray-900 font-mono">{{ permission.name }}</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Module
                                </label>
                                <a-tag color="blue" class="capitalize">
                                    {{ permission.module }}
                                </a-tag>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Action
                                </label>
                                <a-tag color="green" class="capitalize">
                                    {{ permission.action }}
                                </a-tag>
                            </div>
                        </div>

                        <!-- Metadata -->
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Guard Name
                                </label>
                                <span class="text-gray-900">{{ permission.guard_name }}</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Created At
                                </label>
                                <span class="text-gray-900">{{ formatDate(permission.created_at) }}</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Updated At
                                </label>
                                <span class="text-gray-900">{{ formatDate(permission.updated_at) }}</span>
                            </div>
                        </div>
                    </div>
                </a-card>

                <!-- Roles Using This Permission -->
                <a-card>
                    <template #title>
                        <div class="flex items-center gap-3">
                            <IconUsers class="text-green-500" size="24" />
                            <span>Roles Using This Permission</span>
                            <a-badge
                                :count="roles.length"
                                :number-style="{ backgroundColor: '#52c41a' }"
                            />
                        </div>
                    </template>

                    <div v-if="roles.length === 0" class="text-center py-8">
                        <IconUsers class="mx-auto text-gray-400 mb-4" size="48" />
                        <p class="text-gray-500">No roles are currently using this permission.</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="role in roles"
                            :key="role.id"
                            class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
                        >
                            <div class="flex items-center gap-3">
                                <IconShield class="text-blue-500" size="20" />
                                <div>
                                    <h4 class="font-medium text-gray-900">{{ role.name }}</h4>
                                    <p class="text-sm text-gray-500">
                                        {{ role.users_count || 0 }} user(s) assigned
                                    </p>
                                </div>
                            </div>
                            <a-tag color="blue">{{ role.name }}</a-tag>
                        </div>
                    </div>
                </a-card>
            </div>
        </ContentLayout>
    </AuthenticatedLayout>
</template>

