<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconPlus, IconShield, IconSettings, IconTrash, IconEdit, IconEye } from "@tabler/icons-vue";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { usePermissions } from "@/Composables/usePermissions";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import PermissionTable from "./components/PermissionTable.vue";

const page = usePage();
const { openModal, isEdit, spinning } = useGlobalVariables();
const { showModal } = useHelpers();

// Use permission composable
const { canManageRoles, isSuperUser } = usePermissions();

const props = defineProps({
    permissions: Object,
    permissionsGrouped: Object,
    canCreate: Boolean,
    canEdit: Boolean,
    canDelete: Boolean,
});

// Filter state
const search = ref("");

// Navigation state
const selectedPermission = ref(null);

// Fetch permissions
const getItems = () => {
    router.reload({
        only: ["permissions"],
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
const { pagination, handleTableChange } = useTable("permissions", tableFilters);

// Methods
const handleAddPermission = () => {
    router.visit(route("permissions.create"));
};

const handleEditPermission = (permission) => {
    router.visit(route("permissions.edit", permission.id));
};

const handleViewPermission = (permission) => {
    router.visit(route("permissions.show", permission.id));
};

// Debug - log the permissions data
console.log("Permissions data:", props.permissions);
console.log("Permissions grouped:", props.permissionsGrouped);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Permission Management" />
        <ContentHeader class="mb-8" title="Permission Management" />

        <ContentLayout title="Permission Management">
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search permissions by name..."
                    class="min-w-[100px] max-w-[400px]"
                />

                <a-button
                    v-if="(canCreate && canManageRoles.value) || isSuperUser"
                    @click="handleAddPermission"
                    type="primary"
                    class="bg-white border flex items-center border-green-500 text-green-500"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                    Add Permission
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
                <PermissionTable
                    :permissions="permissions?.data || []"
                    :loading="spinning"
                    :pagination="pagination"
                    :can-edit="(canEdit && canManageRoles.value) || isSuperUser"
                    :can-delete="
                        (canDelete && canManageRoles.value) || isSuperUser
                    "
                    @change="handleTableChange"
                    @edit="handleEditPermission"
                    @view="handleViewPermission"
                />
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>

