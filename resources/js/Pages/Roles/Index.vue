<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconPlus, IconShield, IconSettings } from "@tabler/icons-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import RoleTable from "./components/RoleTable.vue";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const page = usePage();
const { openModal, isEdit, spinning } = useGlobalVariables();

const { showModal } = useHelpers();

// Use permission composable
const isSuperUser = computed(() => usePage().props.auth?.user?.data?.is_super_user || false);

const props = defineProps({
    roles: Object,
    permissions: Object,
    canCreate: Boolean,
    canEdit: Boolean,
    canDelete: Boolean,
});

// Filter state
const search = ref("");

// Navigation state
const selectedRole = ref(null);

// Fetch roles
const getItems = () => {
    router.reload({
        only: ["roles"],
        preserveScroll: true,
        data: {
            page: 1,
            search: search.value || undefined,
        },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

watchDebounced(search, getItems, { debounce: 300 });

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [],
});

// Table composable
const tableFilters = { search };
const { pagination, handleTableChange } = useTable("roles", tableFilters);

// Methods
const handleAddRole = () => {
    router.visit(route("roles.create"));
};

const handleEditRole = (role) => {
    router.visit(route("roles.edit", role.id));
};

const handleViewRole = (role) => {
    router.visit(route("roles.show", role.id));
};

const handlePermissionMatrix = () => {
    router.visit(route("roles.permission-matrix"));
};

// Debug - log the roles data
console.log("Roles data:", props.roles);
console.log("Permissions data:", props.permissions);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Role Management" />
        <ContentHeader class="mb-8" title="Role Management" />

        <ContentLayout title="Role Management">
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search roles by name..."
                    class="min-w-[100px] max-w-[400px]"
                />

                <a-button
                    v-if="usePermissionsV2('roles.store')"
                    @click="handleAddRole"
                    type="primary"
                    class="bg-white border flex items-center border-green-500 text-green-500"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                    Add Role
                </a-button>
                <a-button
                    v-if="usePermissionsV2('roles.index') || isSuperUser"
                    @click="handlePermissionMatrix"
                    type="default"
                    class="bg-white border flex items-center border-blue-500 text-blue-500"
                >
                    <template #icon>
                        <IconSettings />
                    </template>
                    Permission Matrix
                </a-button>
            </template>

            <!-- Active Filters -->
            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="
                        () =>
                            Object.keys(filters).forEach(
                                (k) => (filters[k] = null)
                            )
                    "
                />
            </template>

            <template #table>
                <RoleTable
                    :roles="roles?.data || []"
                    :loading="spinning"
                    :pagination="pagination"
                    :can-edit="(canEdit && usePermissionsV2('roles.edit')) || isSuperUser"
                    :can-delete="
                        (canDelete && usePermissionsV2('roles.destroy')) || isSuperUser
                    "
                    @change="handleTableChange"
                    @edit="handleEditRole"
                    @view="handleViewRole"
                />
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
