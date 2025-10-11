<script setup>
import { computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import {
    IconArrowLeft,
    IconShield,
    IconUsers,
    IconEdit,
} from "@tabler/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useHelpers } from "@/Composables/useHelpers";

const props = defineProps({
    permission: Object,
    roles: Array,
});

const { formatDateTime } = useHelpers();
const isSuperUser = computed(() => usePage().props.auth?.user?.data?.is_super_user || false);

// Computed
const canEdit = computed(() => usePermissionsV2('permissions.update') || isSuperUser.value);

// Methods
const handleEdit = () => {
    router.visit(route("permissions.edit", props.permission.id));
};

const handleBack = () => {
    router.visit(route("permissions.index"));
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Permission Details" />
        <ContentHeader title="Permission Details" />

        <div class="max-w-4xl mx-auto p-6 space-y-6">
            <!-- Permission Info Card -->
            <a-card class="mb-6">
                <template #title>
                    <div class="flex items-center gap-3">
                        <IconShield class="text-blue-500" size="24" />
                        <span>{{ permission.name }}</span>
                    </div>
                </template>
                <template #extra>
                    <div class="flex items-end justify-end gap-2 min-w-[200px]">
                        <a-button
                            @click="handleBack"
                            type="default"
                            class="flex gap-2"
                        >
                            <template #icon>
                                <IconArrowLeft />
                            </template>
                            Back
                        </a-button>
                    </div>
                </template>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Basic Info -->
                    <div class="space-y-4">
                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Permission Name
                            </label>
                            <div class="flex items-center gap-2">
                                <IconShield class="text-blue-500" size="16" />
                                <span class="text-gray-900 font-mono w-fit">{{
                                    permission.data?.name || "N/A"
                                }}</span>
                            </div>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Module
                            </label>
                            <a-tag color="blue" class="capitalize w-fit">
                                {{ permission.data?.module }}
                            </a-tag>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Action
                            </label>
                            <a-tag color="green" class="capitalize w-fit">
                                {{ permission.data?.action }}
                            </a-tag>
                        </div>
                    </div>

                    <!-- Metadata -->
                    <div class="space-y-4">
                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Guard Name
                            </label>
                            <span class="text-gray-900">{{
                                permission?.data?.name || "N/A"
                            }}</span>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Created At
                            </label>
                            <span class="text-gray-900">{{
                                formatDateTime(permission?.data.created_at)
                            }}</span>
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Updated At
                            </label>
                            <span class="text-gray-900">{{
                                formatDateTime(permission?.data.updated_at)
                            }}</span>
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
                    <p class="text-gray-500">
                        No roles are currently using this permission.
                    </p>
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
                                <h4 class="font-medium text-gray-900">
                                    {{ role.name }}
                                </h4>
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
    </AuthenticatedLayout>
</template>
