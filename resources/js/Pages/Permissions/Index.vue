<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { watchDebounced } from "@vueuse/core";
import { IconPlus, IconShield, IconSettings, IconTrash, IconEdit, IconEye } from "@tabler/icons-vue";
import { Modal, notification } from "ant-design-vue";
import axios from "axios";
import { useTable } from "@/Composables/useTable";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import PermissionTable from "./components/PermissionTable.vue";
import PermissionCreateModal from "./components/PermissionCreateModal.vue";
import PermissionEditModal from "./components/PermissionEditModal.vue";

const page = usePage();
const { openModal, isEdit, spinning } = useGlobalVariables();
const { showModal } = useHelpers();

// Use permission composable
const isSuperUser = computed(() => usePage().props.auth?.user?.data?.is_super_user || false);

const props = defineProps({
    items: Object,
    permissionsGrouped: Object,
    canCreate: Boolean,
    canEdit: Boolean,
    canDelete: Boolean,
});

// Filter state
const search = ref("");
const module = ref(null);

// Modal state
const showCreateModal = ref(false);
const showEditModal = ref(false);
const selectedPermission = ref(null);

// Fetch permissions
const getItems = () => {
    router.reload({
        only: ["items"],
        preserveScroll: true,
        data: {
            page: 1,
            search: search.value || undefined,
            module: module.value || undefined,
        },
        onStart: () => (spinning.value = true),
        onFinish: () => (spinning.value = false),
    });
};

watchDebounced(search, getItems, { debounce: 300 });

// Filters setup
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems,
    configs: [
        {
            label: "Module",
            key: "module",
            ref: module,
            getLabel: toLabel(
                computed(() =>
                    Object.keys(props.permissionsGrouped || {}).map((moduleName) => ({
                        label: moduleName,
                        value: moduleName,
                    }))
                )
            ),
        },
    ],
});

// FilterDropdown configuration
const filtersConfig = [
    {
        key: "module",
        label: "Module",
        type: "select",
        options: Object.keys(props.permissionsGrouped || {}).map((moduleName) => ({
            label: moduleName,
            value: moduleName,
        })),
    },
];

// Table composable
const tableFilters = { search, module };
const { pagination, handleTableChange } = useTable("items", tableFilters);

// Methods
const handleAddPermission = () => {
    showCreateModal.value = true;
};

const handleEditPermission = (permission) => {
    selectedPermission.value = permission;
    showEditModal.value = true;
};

const handleViewPermission = (permission) => {
    router.visit(route("permissions.show", permission.id));
};

const handleModalClose = () => {
    showCreateModal.value = false;
    showEditModal.value = false;
    selectedPermission.value = null;
};

const handlePermissionSaved = () => {
    handleModalClose();
    getItems(); // Refresh the list
};

const handleDeactivatePermission = (permission) => {
    Modal.confirm({
        title: "Deactivate Permission",
        content: `Are you sure you want to deactivate the permission "${permission.name}"? This will prevent it from being assigned to roles.`,
        okText: "Deactivate",
        okType: "warning",
        cancelText: "Cancel",
        onOk() {
            axios
                .post(route("permissions.deactivate", permission.id))
                .then((response) => {
                    notification.success({
                        message: "Success",
                        description: response.data.message,
                    });
                    getItems(); // Refresh the list
                })
                .catch((error) => {
                    console.error("Deactivate error:", error);
                    notification.error({
                        message: "Error",
                        description:
                            error.response?.data?.message ||
                            "Failed to deactivate permission.",
                    });
                });
        },
    });
};

const handleActivatePermission = (permission) => {
    Modal.confirm({
        title: "Activate Permission",
        content: `Are you sure you want to activate the permission "${permission.name}"? This will make it available for assignment to roles.`,
        okText: "Activate",
        okType: "primary",
        cancelText: "Cancel",
        onOk() {
            axios
                .post(route("permissions.activate", permission.id))
                .then((response) => {
                    notification.success({
                        message: "Success",
                        description: response.data.message,
                    });
                    getItems(); // Refresh the list
                })
                .catch((error) => {
                    console.error("Activate error:", error);
                    notification.error({
                        message: "Error",
                        description:
                            error.response?.data?.message ||
                            "Failed to activate permission.",
                    });
                });
        },
    });
};

const handleDeletePermission = (permission) => {
    Modal.confirm({
        title: "Delete Permission",
        content: `Are you sure you want to permanently delete the permission "${permission.name}"? This action cannot be undone.`,
        okText: "Delete",
        okType: "danger",
        cancelText: "Cancel",
        onOk() {
            axios
                .delete(route("permissions.destroy", permission.id))
                .then((response) => {
                    notification.success({
                        message: "Success",
                        description: response.data.message,
                    });
                    getItems(); // Refresh the list
                })
                .catch((error) => {
                    console.error("Delete error:", error);
                    notification.error({
                        message: "Error",
                        description:
                            error.response?.data?.message ||
                            "Failed to delete permission.",
                    });
                });
        },
    });
};

// Debug - log the permissions data
console.log("Permissions data:", props.items);
console.log("Permissions grouped:", props.permissionsGrouped);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Permission Management" />
        <ContentHeader class="mb-8" title="Permission Management" />

        <ContentLayout>
            <template #filters>
                <RefreshButton :loading="spinning" @click="getItems" />
                <a-input-search
                    v-model:value="search"
                    placeholder="Search permissions by name..."
                    class="min-w-[100px] max-w-[400px]"
                />

                <a-button
                    v-if="(canCreate && usePermissionsV2('permissions.store')) || isSuperUser"
                    @click="handleAddPermission"
                    type="primary"
                    class="bg-white border flex items-center border-green-500 text-green-500"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                    Add Permission
                </a-button>
                <FilterDropdown v-model="filters" :filters="filtersConfig" />
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
                    :permissions="items?.data || []"
                    :loading="spinning"
                    :pagination="pagination"
                    :can-edit="(canEdit && usePermissionsV2('permissions.update')) || isSuperUser"
                    :can-delete="
                        (canDelete && usePermissionsV2('permissions.destroy')) || isSuperUser
                    "
                    @change="handleTableChange"
                    @edit="handleEditPermission"
                    @view="handleViewPermission"
                    @deactivate="handleDeactivatePermission"
                    @activate="handleActivatePermission"
                    @delete="handleDeletePermission"
                />
            </template>
        </ContentLayout>

        <!-- Create Permission Modal -->
        <PermissionCreateModal
            :visible="showCreateModal"
            @close="handleModalClose"
            @saved="handlePermissionSaved"
        />

        <!-- Edit Permission Modal -->
        <PermissionEditModal
            :visible="showEditModal"
            :permission="selectedPermission"
            @close="handleModalClose"
            @saved="handlePermissionSaved"
        />
    </AuthenticatedLayout>
</template>

